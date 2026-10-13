<?php

namespace App\Http\Requests\Farms;

use App\Enums\IdDocumentType;
use App\Enums\VerificationDocumentType;
use App\Models\Farm;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Multipart form: documents[0][type]=identity, documents[0][file]=<upload>, ...
 */
class SubmitFarmVerificationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manageVerification', $this->route('farm'));
    }

    public function rules(): array
    {
        return [
            'id_document_type' => ['required', Rule::enum(IdDocumentType::class)],
            'id_number' => ['required', 'string', 'max:50'],
            'business_registration_number' => [
                Rule::requiredIf(fn () => $this->farm()->farmer_type->requiresBusinessRegistration()),
                'nullable', 'string', 'max:100',
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'documents' => ['required', 'array', 'min:1', 'max:6'],
            'documents.*.type' => ['required', Rule::enum(VerificationDocumentType::class)],
            'documents.*.file' => [
                'required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:'.config('mundalinq.farms.max_document_kb'),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $types = collect($this->input('documents', []))->pluck('type');

                if (! $types->contains(VerificationDocumentType::Identity->value)) {
                    $validator->errors()->add('documents', __('Upload a photo or scan of your ID document.'));
                }

                if ($this->farm()->farmer_type->requiresBusinessRegistration()
                    && ! $types->contains(VerificationDocumentType::BusinessRegistration->value)) {
                    $validator->errors()->add('documents', __('Upload your business or cooperative registration certificate.'));
                }
            },
        ];
    }

    public function details(): array
    {
        return collect($this->validated())->except('documents')->all();
    }

    /**
     * @return list<array{type: string, file: \Illuminate\Http\UploadedFile}>
     */
    public function documents(): array
    {
        return array_values($this->validated('documents'));
    }

    private function farm(): Farm
    {
        return $this->route('farm');
    }
}
