<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreJarvisVrmRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'vrm' => [
                'bail',
                'required',
                'file',
                'extensions:vrm',
                'max:102400',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $this->isValidVrmContainer($value)) {
                        $fail('The uploaded file must be a valid VRM 0.x or VRM 1.0 model.');
                    }
                },
            ],
        ];
    }

    private function isValidVrmContainer(UploadedFile $file): bool
    {
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            return false;
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $header = fread($handle, 12);
            if ($header === false || strlen($header) !== 12 || substr($header, 0, 4) !== 'glTF') {
                return false;
            }

            $container = unpack('Vversion/Vlength', substr($header, 4, 8));
            if ($container === false || $container['version'] !== 2 || $container['length'] !== $file->getSize()) {
                return false;
            }

            $chunkHeader = fread($handle, 8);
            if ($chunkHeader === false || strlen($chunkHeader) !== 8) {
                return false;
            }

            $chunk = unpack('Vlength/Vtype', $chunkHeader);
            if (
                $chunk === false
                || $chunk['type'] !== 0x4E4F534A
                || $chunk['length'] < 2
                || $chunk['length'] > 16 * 1024 * 1024
                || $chunk['length'] > $file->getSize() - 20
            ) {
                return false;
            }

            $json = fread($handle, $chunk['length']);
            if ($json === false || strlen($json) !== $chunk['length']) {
                return false;
            }

            $document = json_decode(trim($json), true);
            if (! is_array($document)) {
                return false;
            }

            $extensions = $document['extensionsUsed'] ?? [];

            return in_array('VRM', $extensions, true)
                || in_array('VRMC_vrm', $extensions, true)
                || array_key_exists('VRM', $document['extensions'] ?? [])
                || array_key_exists('VRMC_vrm', $document['extensions'] ?? []);
        } finally {
            fclose($handle);
        }
    }
}
