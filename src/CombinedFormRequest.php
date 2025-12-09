<?php

namespace Maskow\CombinedRequest;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
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
    protected array $requestParameters          = [];
    
    /** @var array Required parameters that must be provided */
    protected array $requiredParameters = [];

    /** @var null|callable(Component, string): void */
    protected static $authorizationNotifier = null;

    /**
     * Build the request from a Livewire component without triggering the automatic HTTP validation pipeline.
     *
     * @param array $parameters Optional array of parameters to bind (e.g., ['team' => $team, 'workspace' => $workspace])
     */
    public static function fromLivewire(Component $component, array $parameters = []): static {
        /** @var static $instance */
        $instance = static::createFrom(app(Request::class), new static);

        $instance->setContainer(app())
            ->setRedirector(app(Redirector::class))
            ->usingLivewireComponent($component)
            ->withParameters($parameters);

        return $instance;
    }

    /**
     * Convenience helper to validate directly from a Livewire component.
     *
     * @param array $parameters Optional array of parameters to bind (e.g., ['team' => $team, 'workspace' => $workspace])
     */
    public static function validateLivewire(Component $component, array $parameters = []): array {
        return static::fromLivewire($component, $parameters)->validateWithLivewire();
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
     * Set parameters for the request (models, values, etc.).
     *
     * @param array $parameters Array of parameters keyed by name (e.g., ['team' => $team, 'workspace' => $workspace])
     */
    public function withParameters(array $parameters): static {
        $this->requestParameters = array_merge($this->requestParameters, $parameters);
        
        // Validate required parameters after setting them
        $this->validateRequiredParameters();

        return $this;
    }

    /**
     * Set a single parameter.
     */
    public function withParameter(string $key, mixed $value): static {
        $this->requestParameters[$key] = $value;
        
        return $this;
    }

    /**
     * Get a parameter by key.
     */
    public function parameter(string $key, mixed $default = null): mixed {
        // First check request parameters (Livewire)
        if (array_key_exists($key, $this->requestParameters)) {
            return $this->requestParameters[$key];
        }
        
        // Then check route parameters (HTTP API)
        $routeResult = parent::route($key, $default);
        if ($routeResult !== $default) {
            return $routeResult;
        }
        
        return $default;
    }

    /**
     * Check if a parameter exists.
     */
    public function hasParameter(string $key): bool {
        return array_key_exists($key, $this->requestParameters) || parent::route($key) !== null;
    }

    /**
     * Get all parameters.
     */
    public function parameters(): array {
        return array_merge($this->requestParameters, parent::route() ?? []);
    }

    /**
     * Validate that all required parameters are present.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateRequiredParameters(): void {
        if (empty($this->requiredParameters)) {
            return;
        }
        
        $missing = [];
        
        foreach ($this->requiredParameters as $param) {
            if (!$this->hasParameter($param) || $this->parameter($param) === null) {
                $missing[] = $param;
            }
        }
        
        if (!empty($missing)) {
            $requestClass = static::class;
            $missingParams = implode(', ', $missing);
            
            throw new InvalidArgumentException(
                "Missing required parameters for {$requestClass}: {$missingParams}. "
                . "Please provide these parameters when calling fromLivewire() or ensure they exist in the route."
            );
        }
    }

    /**
     * Override route method for backward compatibility.
     */
    public function route($param = null, $default = null) {
        if ($param === null) {
            return parent::route($param, $default);
        }
        
        return $this->parameter($param, $default);
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
            // Validate required parameters are present
            $this->validateRequiredParameters();
            
            // Prepare the request data from Livewire component properties.
            $this->prepareLivewireValidationData();

            $this->runLivewireAuthorization();

            $validator = $this->getValidatorInstance();

            if ($validator->fails()) {
                $this->failedValidation($validator);
            }

            $this->passedValidation();

            $validated = $this->validator->validated();

            // Mirror Livewire's built-in validation behavior: clear old errors on success.
            $this->livewireComponent->resetErrorBag();

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
        } catch (AuthorizationException $e) {
            $this->throwLivewireAuthorizationException($e);
        }

        if ($result instanceof Response) {
            if ($result->denied()) {
                $message   = $result->message() ?: $this->authorizationMessage();
                $exception = (new AuthorizationException($message, $result->code()))
                    ->setResponse($result);

                if ($result->status()) {
                    $exception->withStatus($result->status());
                }

                $this->throwLivewireAuthorizationException($exception);
            }

            return;
        }

        if ($result === false) {
            $this->throwLivewireAuthorizationException(new AuthorizationException($this->authorizationMessage()));
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

    /**
     * Get validated data (ensuring validation has run for both HTTP and Livewire).
     *
     * @param  mixed  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function validated($key = null, $default = null) {
        $this->ensureValidatorIsReady();

        return parent::validated($key, $default);
    }

    protected function failedValidation(Validator $validator) {
        if ($this->runningLivewireValidation) {
            throw (new ValidationException($validator))->errorBag($this->errorBag);
        }

        parent::failedValidation($validator);
    }

    protected function failedAuthorization() {
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

    /**
     * Ensure a validator exists before accessing validated data.
     */
    protected function ensureValidatorIsReady(): void {
        if ($this->validator) {
            return;
        }

        if ($this->livewireComponent) {
            $this->validateWithLivewire();

            return;
        }

        $this->validateResolved();
    }
}
