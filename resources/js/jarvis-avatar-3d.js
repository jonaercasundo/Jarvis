const wrap = document.getElementById('jarvis-avatar-wrap');
const canvas = document.getElementById('jc-avatar-3d');
const openButton = document.getElementById('jc-avatar-vrm-open');
const fileInput = document.getElementById('jc-avatar-vrm-file');
const status = document.getElementById('jc-avatar-vrm-status');
const buttonText = document.getElementById('jc-avatar-vrm-button-text');

if (wrap && canvas instanceof HTMLCanvasElement && openButton && fileInput) {
    const pointer = { x: 0, y: 0 };
    const gaze = { x: 0, y: 0 };
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let THREE = null;
    let VRMUtils = null;
    let loader = null;
    let scene = null;
    let camera = null;
    let clock = null;
    let lookTarget = null;
    let renderer = null;
    let activeVrm = null;
    let particleLandscape = null;
    let landscapePointMaterial = null;
    let runtimePromise = null;
    let state = window.jcAiStateName || 'idle';
    let nextBlinkAt = performance.now() + 1800;
    let blinkUntil = 0;
    let elapsed = 0;
    let avatarBaseY = 0;
    let avatarEyeY = 1.52;
    let avatarLookTargetZ = 2.2;
    let avatarChangeInProgress = false;

    const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

    function setStatus(message) {
        if (status) {
            status.textContent = message;
            status.classList.toggle('hidden', message.length === 0);
        }
    }

    function ensureThreeRuntime() {
        if (!runtimePromise) {
            runtimePromise = Promise.all([
                import('three'),
                import('three/addons/loaders/GLTFLoader.js'),
                import('@pixiv/three-vrm'),
            ]).then(([three, gltf, vrm]) => {
                THREE = three;
                VRMUtils = vrm.VRMUtils;
                loader = new gltf.GLTFLoader();
                loader.register((parser) => new vrm.VRMLoaderPlugin(parser));

                scene = new THREE.Scene();
                camera = new THREE.PerspectiveCamera(28, 1, 0.05, 100);
                clock = new THREE.Clock();
                lookTarget = new THREE.Object3D();
                scene.add(lookTarget);
                scene.add(new THREE.HemisphereLight(0xc8edff, 0x10131d, 1.65));

                const keyLight = new THREE.DirectionalLight(0xe8f6ff, 2.2);
                keyLight.position.set(-1.2, 2.2, 2.5);
                scene.add(keyLight);

                const amberLight = new THREE.PointLight(0xff762f, 5.5, 5.5, 2);
                amberLight.position.set(-1.3, 1.5, 1.1);
                scene.add(amberLight);

                const cyanLight = new THREE.PointLight(0x24cfff, 6.5, 5.5, 2);
                cyanLight.position.set(1.2, 1.8, 1.2);
                scene.add(cyanLight);
            }).catch((error) => {
                runtimePromise = null;
                throw error;
            });
        }

        return runtimePromise;
    }

    function ensureRenderer() {
        if (renderer) {
            return;
        }

        renderer = new THREE.WebGLRenderer({
            canvas,
            alpha: true,
            antialias: true,
            powerPreference: 'high-performance',
        });
        renderer.outputColorSpace = THREE.SRGBColorSpace;
        renderer.toneMapping = THREE.ACESFilmicToneMapping;
        renderer.toneMappingExposure = 1.1;
        renderer.setClearColor(0x000000, 0);
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));

        resizeRenderer();
        window.addEventListener('resize', resizeRenderer, { passive: true });

        renderer.setAnimationLoop(() => {
            if (document.hidden) {
                return;
            }

            const delta = Math.min(clock.getDelta(), 0.05);
            elapsed += delta;

            if (activeVrm) {
                updateVrm(delta);
            } else if (particleLandscape && !reducedMotion) {
                updateLandscape();
            }

            renderer.render(scene, camera);
        });
    }

    function resizeRenderer() {
        if (!renderer) {
            return;
        }

        const width = Math.max(1, wrap.clientWidth);
        const height = Math.max(1, wrap.clientHeight);
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();

        if (activeVrm) {
            fitVrmCamera(activeVrm.scene);
        }
    }

    function makeParticleLandscape() {
        if (particleLandscape) {
            return;
        }

        particleLandscape = new THREE.Group();
        particleLandscape.name = 'JARVIS particle wave';
        const columns = 174;
        const rows = 84;
        const pointCount = columns * rows;
        const pointPositions = new Float32Array(pointCount * 3);
        const pointColors = new Float32Array(pointCount * 3);
        const coordinates = new Float32Array(pointCount * 2);
        let seed = 41729;
        const random = () => {
            seed = (seed * 16807) % 2147483647;
            return (seed - 1) / 2147483646;
        };
        const bright = new THREE.Color('#b9f7ff');
        const deep = new THREE.Color('#087fa1');
        const edgePairs = [];
        for (let row = 0; row < rows; row += 1) {
            const z = THREE.MathUtils.lerp(-5.8, 2.5, row / (rows - 1));
            for (let column = 0; column < columns; column += 1) {
                const index = row * columns + column;
                const x = THREE.MathUtils.lerp(-7.4, 7.4, column / (columns - 1));
                coordinates[index * 2] = x;
                coordinates[index * 2 + 1] = z;
                const farGlow = 1 - row / (rows - 1);
                const sparkle = random() > 0.992 ? 0.5 : 0;
                const color = deep.clone().lerp(bright, 0.12 + farGlow * 0.24 + sparkle);
                pointColors[index * 3] = color.r;
                pointColors[index * 3 + 1] = color.g;
                pointColors[index * 3 + 2] = color.b;
            }
        }
        for (let row = 0; row < rows; row += 4) {
            for (let column = 0; column < columns - 1; column += 1) {
                edgePairs.push(row * columns + column, row * columns + column + 1);
            }
        }
        const waveHeight = (x, z, time) => {
            const broadWave = 0.16 * Math.sin(x * 0.82 + z * 0.68 + time * 0.24)
                + 0.12 * Math.sin(x * 1.37 - z * 0.44 - time * 0.18);
            const envelope = Math.exp(-(((z + 2.1) / 3.0) ** 2));
            const crest = envelope * (
                0.34
                + 0.43 * Math.sin(x * 0.76 + 0.15 + Math.sin(z * 0.35) * 0.3)
                + 0.2 * Math.cos(x * 1.42 - z * 0.21 + time * 0.12)
            );
            const centerRise = 0.54 * Math.exp(-(((x - 0.2) / 2.5) ** 2) - (((z + 2.5) / 2.0) ** 2));
            const sideRise = 0.3 * Math.exp(-(((x + 4.2) / 1.2) ** 2) - (((z + 1.8) / 1.65) ** 2));
            return broadWave + crest + centerRise + sideRise;
        };
        const updateWaveGeometry = (time) => {
            for (let index = 0; index < pointCount; index += 1) {
                const x = coordinates[index * 2];
                const z = coordinates[index * 2 + 1];
                pointPositions[index * 3] = x;
                pointPositions[index * 3 + 1] = waveHeight(x, z, time);
                pointPositions[index * 3 + 2] = z;
            }
        };
        updateWaveGeometry(0);
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(pointPositions, 3));
        geometry.setAttribute('color', new THREE.BufferAttribute(pointColors, 3));
        const linePositions = new Float32Array(edgePairs.length * 3);
        const lineGeometry = new THREE.BufferGeometry();
        lineGeometry.setAttribute('position', new THREE.BufferAttribute(linePositions, 3));
        landscapePointMaterial = new THREE.PointsMaterial({
            size: 0.027,
            sizeAttenuation: true,
            vertexColors: true,
            transparent: true,
            opacity: 0.94,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            toneMapped: false,
        });
        particleLandscape.add(new THREE.Points(geometry, landscapePointMaterial));
        particleLandscape.add(new THREE.LineSegments(lineGeometry, new THREE.LineBasicMaterial({
            color: '#39dfff',
            transparent: true,
            opacity: 0.19,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            toneMapped: false,
        })));

        const starPositions = [];
        for (let index = 0; index < 180; index += 1) {
            starPositions.push((random() - 0.5) * 14, 1.3 + random() * 2.3, -4.6 + random() * 1.6);
        }
        const starGeometry = new THREE.BufferGeometry();
        starGeometry.setAttribute('position', new THREE.Float32BufferAttribute(starPositions, 3));
        particleLandscape.add(new THREE.Points(starGeometry, new THREE.PointsMaterial({
            color: '#aef5ff',
            size: 0.035,
            transparent: true,
            opacity: 0.58,
            sizeAttenuation: true,
            depthWrite: false,
            blending: THREE.AdditiveBlending,
            toneMapped: false,
        })));

        scene.add(particleLandscape);
        camera.fov = 39;
        camera.position.set(0, 2.65, 8.6);
        camera.lookAt(0, 0.05, -1.25);
        camera.updateProjectionMatrix();

        particleLandscape.userData.update = (time) => {
            updateWaveGeometry(time);
            geometry.attributes.position.needsUpdate = true;
            let lineOffset = 0;
            for (const index of edgePairs) {
                linePositions[lineOffset] = pointPositions[index * 3];
                linePositions[lineOffset + 1] = pointPositions[index * 3 + 1];
                linePositions[lineOffset + 2] = pointPositions[index * 3 + 2];
                lineOffset += 3;
            }
            lineGeometry.attributes.position.needsUpdate = true;
            lineGeometry.computeBoundingSphere();
        };
        particleLandscape.userData.update(0);
    }

    function updateLandscape() {
        particleLandscape.userData.update(elapsed);
        if (!reducedMotion) {
            camera.position.x += (pointer.x * 0.22 - camera.position.x) * 0.012;
            camera.position.y += (2.65 - pointer.y * 0.08 - camera.position.y) * 0.012;
            camera.lookAt(pointer.x * 0.12, 0.05, -1.25);
        }
        const stateGlow = state === 'speaking' ? 0.98 : state === 'listening' ? 0.94 : 0.88;
        landscapePointMaterial.opacity = stateGlow + Math.sin(elapsed * 1.1) * 0.035;
    }

    async function startParticleLandscape() {
        try {
            await ensureThreeRuntime();
            ensureRenderer();
            makeParticleLandscape();
            wrap.classList.add('jc-particles-ready');
        } catch (error) {
            setStatus('3D particles are unavailable in this browser.');
        }
    }

    function disposeActiveVrm() {
        if (!activeVrm) {
            return;
        }

        scene.remove(activeVrm.scene);
        VRMUtils.deepDispose(activeVrm.scene);
        activeVrm = null;
        wrap.classList.remove('jc-vrm-ready');
        if (particleLandscape) {
            particleLandscape.visible = true;
        }
        console.info('[VRM] Previous avatar disposed');
    }

    function fitVrmCamera(model) {
        const bounds = new THREE.Box3().setFromObject(model);
        const size = bounds.getSize(new THREE.Vector3());
        const center = bounds.getCenter(new THREE.Vector3());
        const verticalFov = THREE.MathUtils.degToRad(35);
        const horizontalFov = 2 * Math.atan(Math.tan(verticalFov / 2) * camera.aspect);
        const verticalDistance = size.y / (2 * Math.tan(verticalFov / 2));
        const horizontalDistance = size.x / (2 * Math.tan(horizontalFov / 2));
        const distance = Math.max(verticalDistance, horizontalDistance, 0.25) * 1.2 + size.z / 2;

        camera.fov = 35;
        camera.position.set(center.x, center.y, center.z + distance);
        camera.lookAt(center);
        camera.updateProjectionMatrix();

        avatarEyeY = center.y + size.y * 0.38;
        avatarLookTargetZ = center.z + Math.max(size.z / 2 + 1, 1.4);
        lookTarget.position.set(center.x, avatarEyeY, avatarLookTargetZ);
    }

    function frameModel(vrm) {
        const bounds = new THREE.Box3().setFromObject(vrm.scene);
        const originalHeight = Math.max(0.1, bounds.max.y - bounds.min.y);
        vrm.scene.scale.setScalar(1.72 / originalHeight);
        vrm.scene.updateMatrixWorld(true);

        const fittedBounds = new THREE.Box3().setFromObject(vrm.scene);
        const centerX = (fittedBounds.min.x + fittedBounds.max.x) / 2;
        vrm.scene.position.x -= centerX;
        vrm.scene.position.y -= fittedBounds.min.y;
        avatarBaseY = vrm.scene.position.y;
        vrm.scene.updateMatrixWorld(true);

        fitVrmCamera(vrm.scene);
    }

    function updateVrm(delta) {
        const now = performance.now();
        const desiredGazeX = pointer.x * 1.1;
        const desiredGazeY = avatarEyeY - pointer.y * 0.42;
        gaze.x += (desiredGazeX - gaze.x) * 0.08;
        gaze.y += (desiredGazeY - gaze.y) * 0.08;
        lookTarget.position.set(gaze.x, gaze.y, avatarLookTargetZ);

        if (activeVrm.lookAt) {
            activeVrm.lookAt.target = lookTarget;
        }

        const expressionManager = activeVrm.expressionManager;
        if (expressionManager) {
            if (now >= nextBlinkAt) {
                blinkUntil = now + 120;
                nextBlinkAt = now + 2200 + Math.random() * 3600;
            }

            if (expressionManager.getExpression('blink')) {
                expressionManager.setValue('blink', now < blinkUntil ? 1 : 0);
            }

            const mouthValue = Number.parseFloat(
                wrap.style.getPropertyValue('--jc-mouth') || '0'
            );
            const speechMotion = state === 'speaking'
                ? 0.14 + Math.abs(Math.sin(elapsed * 8.5)) * 0.22
                : 0;
            const mouthOpen = clamp(Math.max(mouthValue || 0, speechMotion), 0, 0.82);

            if (expressionManager.getExpression('aa')) {
                expressionManager.setValue('aa', mouthOpen);
            }
            if (expressionManager.getExpression('oh')) {
                expressionManager.setValue('oh', mouthOpen * 0.16);
            }
        }

        const head = activeVrm.humanoid.getNormalizedBoneNode('head');
        if (head) {
            head.rotation.y = gaze.x * 0.12;
            head.rotation.x = (avatarEyeY - gaze.y) * 0.08;
        }

        const root = activeVrm.scene;
        root.position.y = avatarBaseY + Math.sin(elapsed * 1.35) * 0.006;
        activeVrm.update(delta);
    }

    async function loadVrmFromUrl(url, label = 'Saved VRM') {
        await ensureThreeRuntime();
        ensureRenderer();
        const gltf = await loader.loadAsync(url);
        const nextVrm = gltf.userData.vrm;
        if (!nextVrm) {
            VRMUtils.deepDispose(gltf.scene);
            throw new Error('The saved file does not contain a VRM avatar.');
        }

        try {
            VRMUtils.removeUnnecessaryVertices(gltf.scene);
            VRMUtils.combineSkeletons(gltf.scene);
            VRMUtils.combineMorphs(nextVrm);
            VRMUtils.rotateVRM0(nextVrm);
            frameModel(nextVrm);
        } catch (error) {
            VRMUtils.deepDispose(gltf.scene);
            throw error;
        }

        activeVrm = nextVrm;
        scene.add(activeVrm.scene);
        if (particleLandscape) {
            particleLandscape.visible = false;
        }
        wrap.classList.remove('jc-vrm-loading');
        wrap.classList.add('jc-vrm-ready');
        if (buttonText) {
            buttonText.textContent = 'Change VRM';
        }
        setStatus(`${label} loaded. 3D avatar is ready.`);
        console.info('[VRM] New avatar loaded');
    }

    async function validateVrmInBrowser(file) {
        await ensureThreeRuntime();
        const objectUrl = URL.createObjectURL(file);
        let gltf = null;

        try {
            gltf = await loader.loadAsync(objectUrl);
            if (!gltf.userData.vrm) {
                throw new Error('That file does not contain a VRM avatar.');
            }
        } finally {
            if (gltf?.scene) {
                VRMUtils.deepDispose(gltf.scene);
            }
            URL.revokeObjectURL(objectUrl);
        }
    }

    async function uploadVrm(file) {
        if (avatarChangeInProgress) {
            return;
        }

        avatarChangeInProgress = true;
        openButton.disabled = true;
        wrap.classList.add('jc-vrm-loading');
        setStatus(`Uploading ${file.name}…`);

        try {
            setStatus(`Checking ${file.name}…`);
            await validateVrmInBrowser(file);

            const formData = new FormData();
            formData.append('vrm', file);
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch('/jarvis/vrm', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                body: formData,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.success || !payload.url) {
                const validationMessage = Object.values(payload.errors || {}).flat()[0];
                throw new Error(validationMessage || payload.message || 'VRM upload failed. Your current avatar was kept.');
            }

            disposeActiveVrm();
            setStatus('Loading saved avatar…');
            await loadVrmFromUrl(payload.url, file.name);
        } catch (error) {
            wrap.classList.remove('jc-vrm-loading');
            setStatus(error instanceof Error ? error.message : 'Could not upload that VRM file.');
        } finally {
            avatarChangeInProgress = false;
            openButton.disabled = false;
        }
    }

    async function loadActiveVrm() {
        try {
            const response = await fetch('/jarvis/vrm/active', {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            if (!payload.url) {
                return;
            }

            wrap.classList.add('jc-vrm-loading');
            await loadVrmFromUrl(payload.url);
        } catch (error) {
            wrap.classList.remove('jc-vrm-loading');
            setStatus('Saved VRM could not be loaded. Particle display is active.');
            console.warn('[VRM] Saved avatar load failed.', error);
        }
    }

    async function initializeAvatar() {
        await startParticleLandscape();
        await loadActiveVrm();
    }

    openButton.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file) {
            void uploadVrm(file);
        }
        fileInput.value = '';
    });

    window.addEventListener('pointermove', (event) => {
        pointer.x = clamp((event.clientX / window.innerWidth) * 2 - 1, -1, 1);
        pointer.y = clamp((event.clientY / window.innerHeight) * 2 - 1, -1, 1);
    }, { passive: true });

    window.addEventListener('jc:avatar-state', (event) => {
        state = event.detail?.state || 'idle';
    });

    state = window.jcAiStateName || state;
    void initializeAvatar();
}
