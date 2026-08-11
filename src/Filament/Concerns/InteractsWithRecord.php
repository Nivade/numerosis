<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use LogicException;

use function Filament\Support\get_model_label;
use function Filament\Support\locale_has_pluralization;

trait InteractsWithRecord
{
    use EvaluatesClosures;

    /**
     * The record instance or key.
     */
    #[Locked]
    public Model|int|string|null $record = null;

    /**
     * @var class-string<Model>|Closure|null
     */
    protected string|Closure|null $model = null;

    protected string|Closure|null $modelLabel = null;

    protected string|Closure|null $pluralModelLabel = null;

    protected string|Closure|null $recordTitle = null;

    protected string|Closure|null $recordTitleAttribute = null;

    protected ?Closure $resolveRecordUsing = null;

    public function mountCanAuthorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function resolveRecord(int|string|null $key): Model
    {
        if ($this->resolveRecordUsing) {
            $record = $this->evaluate($this->resolveRecordUsing, [
                'key' => $key,
            ]);

            throw_unless($record instanceof Model, ModelNotFoundException::class);

            return $record;
        }

        $model = $this->getModel();

        throw_if(blank($model), LogicException::class, "Could not resolve record from key [{$key}] without a model class.");

        return $model::query()->findOrFail($key);
    }

    public function resolveRecordUsing(?Closure $callback): static
    {
        $this->resolveRecordUsing = $callback;

        return $this;
    }

    public function getRecord(): Model
    {
        abort_unless($this->record instanceof Model, 404);

        return $this->record;
    }

    public function hasRecord(): bool
    {
        return filled($this->record) && $this->record instanceof Model;
    }

    /**
     * Set the model class for this page.
     *
     * @param  class-string<Model>|Closure|null  $model
     */
    public function model(string|Closure|null $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the model class.
     *
     * @return class-string<Model>|null
     */
    public function getModel(): ?string
    {
        $model = $this->evaluate($this->model);

        if (filled($model)) {
            return $model;
        }

        if ($this->hasRecord()) {
            return $this->getRecord()::class;
        }

        return null;
    }

    public function modelLabel(string|Closure|null $label): static
    {
        $this->modelLabel = $label;

        return $this;
    }

    public function getModelLabel(): ?string
    {
        $label = $this->evaluate($this->modelLabel);

        if (filled($label)) {
            return $label;
        }

        $model = $this->getModel();

        if (blank($model)) {
            return null;
        }

        return get_model_label($model);
    }

    public function getTitleCaseModelLabel(): ?string
    {
        $modelLabel = $this->getModelLabel();

        if (blank($modelLabel)) {
            return null;
        }

        return Str::ucwords($modelLabel);
    }

    public function pluralModelLabel(string|Closure|null $label): static
    {
        $this->pluralModelLabel = $label;

        return $this;
    }

    public function getPluralModelLabel(): ?string
    {
        $label = $this->evaluate($this->pluralModelLabel);

        if (filled($label)) {
            return $label;
        }

        $singularLabel = $this->getModelLabel();

        if (blank($singularLabel)) {
            return null;
        }

        if (locale_has_pluralization()) {
            return Str::plural($singularLabel);
        }

        return $singularLabel;
    }

    public function getTitleCasePluralModelLabel(): ?string
    {
        $pluralModelLabel = $this->getPluralModelLabel();

        if (blank($pluralModelLabel)) {
            return null;
        }

        return Str::ucwords($pluralModelLabel);
    }

    public function recordTitle(string|Closure|null $title): static
    {
        $this->recordTitle = $title;

        return $this;
    }

    public function recordTitleAttribute(string|Closure|null $attribute): static
    {
        $this->recordTitleAttribute = $attribute;

        return $this;
    }

    public function getRecordTitle(?Model $record = null): string|Htmlable
    {
        $record ??= $this->hasRecord() ? $this->getRecord() : null;

        if (blank($record)) {
            return $this->getTitleCaseModelLabel() ?? '';
        }

        if (filled($title = $this->getCustomRecordTitle($record))) {
            return $title;
        }

        return $this->getTitleCaseModelLabel() ?? '';
    }

    public function getCustomRecordTitle(?Model $record = null): ?string
    {
        $record ??= $this->hasRecord() ? $this->getRecord() : null;

        $title = $this->evaluate(
            $this->recordTitle,
            namedInjections: [
                'record' => $record,
            ],
            typedInjections: ($record instanceof Model) ? [
                Model::class => $record,
                $record::class => $record,
            ] : [],
        );

        if (filled($title)) {
            return $title;
        }

        $titleAttribute = $this->getCustomRecordTitleAttribute();

        if (blank($titleAttribute)) {
            return null;
        }

        if (str_contains((string) $titleAttribute, '->')) {
            $titleAttribute = str_replace('->', '.', $titleAttribute);
        }

        $value = data_get($record, $titleAttribute);

        return is_scalar($value) ? (string) $value : null;
    }

    protected function getRecordTitleAttribute(): ?string
    {
        return $this->getCustomRecordTitleAttribute();
    }

    protected function getCustomRecordTitleAttribute(): ?string
    {
        return $this->evaluate($this->recordTitleAttribute);
    }

    public function hasCustomRecordTitle(): bool
    {
        return filled($this->recordTitle);
    }

    public function hasCustomRecordTitleAttribute(): bool
    {
        return $this->recordTitleAttribute !== null;
    }

    public function getDefaultActionRecord(Action $action): ?Model
    {
        return $this->hasRecord() ? $this->getRecord() : null;
    }

    public function getDefaultActionRecordTitle(Action $action): ?string
    {
        if (! $this->hasRecord()) {
            return null;
        }

        $title = $this->getRecordTitle();

        return $title instanceof Htmlable ? $title->toHtml() : $title;
    }

    /**
     * Get the default action model.
     *
     * @return class-string<Model>|null
     */
    public function getDefaultActionModel(Action $action): ?string
    {
        return $this->getModel();
    }

    public function getDefaultActionModelLabel(Action $action): ?string
    {
        return $this->getModelLabel();
    }

    /**
     * Get the mounted action schema model.
     *
     * @return Model|class-string<Model>|null
     */
    protected function getMountedActionSchemaModel(): Model|string|null
    {
        return $this->hasRecord() ? $this->getRecord() : $this->getModel();
    }

    /**
     * Get widget data including the record.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        $data = parent::getWidgetData();

        if ($this->hasRecord()) {
            $data['record'] = $this->getRecord();
        }

        return $data;
    }

    /**
     * Hook called after an action is executed.
     */
    protected function afterActionCalled(Action $action): void
    {
        parent::afterActionCalled($action);

        if ($this->hasRecord() && ! $this->getRecord()->exists) {
            // Ensure that Livewire does not attempt to dehydrate
            // a record that does not exist.
            $this->record = null;
        }
    }
}
