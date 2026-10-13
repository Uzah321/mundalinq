<?php

namespace App\Http\Requests\Catalog;

use App\Enums\QualityGrade;
use App\Models\Farm;
use App\Models\Unit;
use App\Rules\DecimalString;
use App\Services\Catalog\VariantSpec;
use App\Support\Market;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    private ?Farm $farm = null;

    private bool $farmResolved = false;

    /** @var list<VariantSpec> */
    private array $variantSpecs = [];

    public function authorize(): Response
    {
        $farm = $this->farm();

        // A missing or unknown farm_id is reported by validation instead.
        return $farm ? Gate::inspect('update', $farm) : Response::allow();
    }

    public function rules(): array
    {
        return [
            'farm_id' => ['bail', 'required', 'uuid', Rule::exists('farms', 'id')->whereNull('deleted_at')],
            'category_id' => ['bail', 'required', 'uuid', Rule::exists('categories', 'id')->where('is_active', true)],
            'farm_location_id' => ['bail', 'nullable', 'uuid'],
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'description' => ['nullable', 'string', 'max:3000'],
            'quality_grade' => ['nullable', Rule::enum(QualityGrade::class)],
            'stock_unit' => ['required', 'string', Rule::exists('units', 'code')],
            'offers_pickup' => ['sometimes', 'boolean'],
            'offers_delivery' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['nullable', DecimalString::quantity()],
            'variants' => ['required', 'array', 'min:1', 'max:'.config('mundalinq.catalog.max_variants')],
            ...VariantRules::for('variants.*.'),
            'initial_stock' => ['nullable', 'array'],
            'initial_stock.quantity' => ['required_with:initial_stock', DecimalString::quantity()],
            'initial_stock.harvested_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.Market::today()],
            'initial_stock.best_before' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.Market::today()],
            'initial_stock.notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The checks below rely on the basic shape being valid.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $errors = $validator->errors();
                $farm = $this->farm();
                $stockUnit = Unit::findByCode($this->input('stock_unit'));

                ProductRules::checkCategory($errors, $this->input('category_id'));
                ProductRules::checkLocation($errors, $farm, $this->input('farm_location_id'));
                ProductRules::checkFulfilment(
                    $errors,
                    $farm,
                    $this->boolean('offers_pickup', $farm->offers_pickup),
                    $this->boolean('offers_delivery', $farm->offers_delivery),
                );
                ProductRules::checkWholeStock($errors, $stockUnit, [
                    'low_stock_threshold' => $this->input('low_stock_threshold'),
                    'initial_stock.quantity' => $this->input('initial_stock.quantity'),
                ]);

                $keys = [];

                foreach ($this->input('variants') as $index => $variant) {
                    $spec = new VariantSpec($variant, $stockUnit);

                    foreach ($spec->errors() as $field => $message) {
                        $errors->add("variants.{$index}.{$field}", $message);
                    }

                    if ($spec->passes()) {
                        if (in_array($spec->key(), $keys, true)) {
                            $errors->add("variants.{$index}.unit", __('This selling option is listed twice.'));
                        }

                        $keys[] = $spec->key();
                    }

                    $this->variantSpecs[] = $spec;
                }
            },
        ];
    }

    public function farm(): ?Farm
    {
        if (! $this->farmResolved) {
            $id = $this->input('farm_id');
            $this->farm = is_string($id) && Str::isUuid($id) ? Farm::find($id) : null;
            $this->farmResolved = true;
        }

        return $this->farm;
    }

    public function productAttributes(): array
    {
        $farm = $this->farm();

        return [
            ...$this->safe()->only(['category_id', 'title', 'description', 'quality_grade', 'low_stock_threshold']),
            'farm_location_id' => $this->validated('farm_location_id') ?? $farm->primaryLocation->id,
            'stock_unit_id' => Unit::idFor($this->validated('stock_unit')),
            'offers_pickup' => $this->boolean('offers_pickup', $farm->offers_pickup),
            'offers_delivery' => $this->boolean('offers_delivery', $farm->offers_delivery),
            'currency' => Market::currency(),
        ];
    }

    /**
     * @return list<VariantSpec>
     */
    public function variantSpecs(): array
    {
        return $this->variantSpecs;
    }

    public function initialStock(): ?array
    {
        return $this->validated('initial_stock');
    }
}
