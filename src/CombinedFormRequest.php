<?php

namespace Maskow\CombinedRequest;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Shared request base that works for both HTTP controllers and Livewire components.
 */
abstract class CombinedFormRequest extends FormRequest {
    protected null|Component $livewireComponent = null;
    protected bool $runningLivewireValidation   = false;
    protected array $livewireData               = [];

    /** @var null|callable(Component, string): void */
    protected static $authorizationNotifier = null;

    /**
     * Build the request from a Livewire component without triggering the automatic HTTP validation pipeline.
     */
    public static function fromLivewire(Component $component): static {
        /** @var static $instance */
        $instance = static::createFrom(app(Request::class), new static);

        $instance->setContainer(app())
            ->setRedirector(app(Redirector::class))
            ->usingLivewireComponent($component);

        return $instance;
    }

    /**
     * Convenience helper to validate directly from a Livewire component.
     */
    public static function validateLivewire(Component $component): array {
        return static::fromLivewire($component)->validateWithLivewire();
    }

    /**
     * Register a callback to notify about Livewire authorization failures.
     */
    public static function notifyAuthorizationUsing(null|callable $callback): void {
        static::$authorizationNotifier = $callback;
    }

    public function usingLivewireComponent(Component $component): static {
        $this->livewireComponent = $component;

        return $this;
    }

    /**
     * Run validation against the Livewire component's public properties.
     */
    public function validateWithLivewire(null|Component $component = null): array {
        if ($component !== null) {
            $this->usingLivewireComponent($component);
        }

        if (! $this->livewireComponent) {
            throw new InvalidArgumentException('A Livewire component instance is required to run validation.');
        }

        // Ensure the container is available when the request was built manually.
        if (! $this->container) {
            $this->setContainer(app())->setRedirector(app(Redirector::class));
        }

        $this->runningLivewireValidation = true;

        try {
            // Prepare the request data from Livewire component properties.
            $this->prepareLivewireValidationData();

            $this->runLivewireAuthorization();

            $validator = $this->getValidatorInstance();

            if ($validator->fails()) {
                $this->failedValidation($validator);
            }

            $this->passedValidation();

            $validated = $this->validator->validated();

            // Keep Livewire state in sync with any prepared/mutated values.
            $this->livewireComponent->fill($validated);

            return $validated;
        } finally {
            $this->runningLivewireValidation = false;
        }
    }

    /**
     * Copy Livewire data onto the request so FormRequest hooks (prepareForValidation, withValidator, etc.) still work.
     */
    protected function prepareLivewireValidationData(): void {
        // Start with a fresh copy of the Livewire component's public properties.
        $this->livewireData = $this->livewireComponent->all();

        // Separate uploaded files from the rest of the payload.
        [$input, $files] = $this->separateFilesFromPayload($this->livewireData);

        // Put the component payload onto the request instance for normal FormRequest processing.
        $this->replace($this->normalizeForRequest($input));

        // Put the files onto the request's file bag.
        $this->files->replace($files);

        // Run any custom preparation logic.
        $this->prepareForValidation();

        // Keep a final copy of the data (including prepareForValidation changes) for the validator.
        $this->livewireData = parent::validationData();
    }

    /**
     * Run authorization for Livewire without triggering a RedirectResponse.
     */
    protected function runLivewireAuthorization(): void {
        if (! method_exists($this, 'authorize')) {
            return;
        }

        try {
            $result = $this->container->call([$this, 'authorize']);
            $result->authorize();
        } catch (AuthorizationException $e) {
            $this->throwLivewireAuthorizationException($e);
        }
    }

    /**
     * Split uploaded files out so the InputBag only receives scalars/arrays.
     *
     * @return array{0: array, 1: array}
     */
    protected function separateFilesFromPayload(array $payload): array {
        $input = [];
        $files = [];

        foreach ($payload as $key => $value) {
            if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
                $value = $value->toArray();
            }

            if ($value instanceof SymfonyUploadedFile) {
                $files[$key] = $value;
                $input[$key] = null;

                continue;
            }

            if (is_array($value)) {
                [$nestedInput, $nestedFiles] = $this->separateFilesFromPayload($value);

                $input[$key] = $nestedInput;

                if ($nestedFiles !== []) {
                    $files[$key] = $nestedFiles;
                }

                continue;
            }

            $input[$key] = $value;
        }

        return [$input, $files];
    }

    public function validationData(): array {
        if ($this->runningLivewireValidation) {
            return $this->livewireData;
        }

        return parent::validationData();
    }

    /**
     * Ensure the request bag receives only scalars/arrays (InputBag restriction).
     */
    protected function normalizeForRequest(mixed $value): mixed {
        if ($value instanceof \UnitEnum) {
            return $value instanceof \BackedEnum ? $value->value : $value->name;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeForRequest($item), $value);
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $this->normalizeForRequest($value->toArray());
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeForRequest($value->jsonSerialize());
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Fallback: drop unknown object types to avoid Symfony InputBag errors.
        return null;
    }

    protected function failedValidation(Validator $validator) {
        if ($this->runningLivewireValidation) {
            throw (new ValidationException($validator))->errorBag($this->errorBag);
        }

        parent::failedValidation($validator);
    }

    protected function failedAuthorization() {
        dd('123');

        if ($this->livewireComponent) {
            $this->throwLivewireAuthorizationException();
        }

        parent::failedAuthorization();
    }

    protected function authorizationMessage(): string {
        return __('This action is unauthorized.');
    }

    protected function throwLivewireAuthorizationException(null|AuthorizationException $e = null): never {
        $message = $e?->getMessage() ?: $this->authorizationMessage();

        $this->notifyLivewireAuthorization($message);

        throw ValidationException::withMessages([
            'authorization' => [$message],
        ])->errorBag($this->errorBag);
    }

    protected function notifyLivewireAuthorization(string $message): void {
        if (! static::$authorizationNotifier || ! $this->livewireComponent) {
            return;
        }

        call_user_func(static::$authorizationNotifier, $this->livewireComponent, $message);
    }
}
