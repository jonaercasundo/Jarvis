let clarificationBusy = false;

function text(value, limit) {
    return typeof value === 'string' ? value.slice(0, limit) : '';
}

export function renderClarification(host, payload) {
    if (!host) return false;
    host.closest('#jarvis-result')?.classList.toggle('jc-clarification-result', payload?.type === 'clarification');
    if (payload?.type !== 'clarification') return false;
    const section = document.createElement('section');
    section.className = 'jc-clarification';
    const question = document.createElement('h2');
    question.className = 'jc-visual-title';
    question.textContent = text(payload.question, 200);
    section.append(question);
    const choices = document.createElement('div');
    choices.className = 'jc-clarification-options';
    (Array.isArray(payload.options) ? payload.options : []).slice(0, 6).forEach((option) => {
        const label = text(option?.label, 50);
        const value = text(option?.value, 100);
        if (!label || !value) return;
        const control = document.createElement('button');
        control.type = 'button';
        control.className = 'jc-visual-button jc-clarification-choice';
        control.textContent = label;
        control.disabled = clarificationBusy;
        control.dataset.clarificationChoice = 'true';
        control.addEventListener('click', () => {
            if (clarificationBusy || control.disabled) return;
            setBusy(true);
            window.dispatchEvent(new CustomEvent('jc:clarification-answer', {
                detail: { answer: value, clarificationId: text(payload.clarification_id, 36) },
            }));
        });
        choices.append(control);
    });
    section.append(choices);
    if (payload.allow_custom_answer) {
        const note = document.createElement('p');
        note.className = 'jc-clarification-note';
        note.textContent = 'Or type your answer below.';
        section.append(note);
    }
    host.replaceChildren(section);
    return true;
}

function setBusy(busy) {
    clarificationBusy = Boolean(busy);
    document.querySelectorAll('[data-clarification-choice]').forEach((control) => { control.disabled = clarificationBusy; });
}

if (typeof window !== 'undefined') {
    window.jcClarification = { render: renderClarification, setBusy };
}
