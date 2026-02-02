<?php

namespace Maskow\CombinedRequest;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * Shared request base that works for both HTTP controllers and Livewire components.
 */
abstract class CombinedFormRequest extends FormRequest {
    protected null|Component $livewireComponent = null;
    private bool $runningLivewireValidation   = false;
    private array $livewireData               = [];
    private array $requestParameters          = [];
    private array $camelToSnakeMap            = [];
    
    /** @var array Required parameters that must be provided */
    protected array $requiredParameters = [];

    /** @var null|callable(Component, string): void */
    protected static $authorizationNotifier = null;

    /** @var bool Global setting to enable automatic camelCase to snake_case conversion for Livewire validation */
    protected static bool $convertCamelCaseToSnakeCase = false;

    /** @var bool Global setting to return validated data in snake_case format (useful for database operations) */
    protected static bool $returnValidatedDataAsSnakeCase = false;

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

    /**
     * Enable or disable automatic camelCase to snake_case conversion for Livewire validation.
     * 
     * When enabled, Livewire component properties in camelCase (e.g., 'listId', 'userName')
     * will be automatically converted to snake_case (e.g., 'list_id', 'user_name') for validation.
     * Validation errors will still reference the original camelCase property names.
     * 
     * @param bool $enabled Whether to enable the conversion (default: false)
     */
    public static function convertCamelCaseToSnakeCase(bool $enabled = true): void {
        static::$convertCamelCaseToSnakeCase = $enabled;
    }

    /**
     * Check if camelCase to snake_case conversion is enabled.
     */
    public static function isCamelCaseConversionEnabled(): bool {
        return static::$convertCamelCaseToSnakeCase;
    }

    /**
     * Configure whether validated data should be returned in snake_case format.
     * 
     * When enabled along with camelCase conversion, validated data will remain in snake_case
     * format (e.g., 'board_id', 'list_id') which is useful for direct database operations.
     * Validation errors will still reference camelCase property names (e.g., 'boardId', 'listId').
     * 
     * This setting only takes effect when convertCamelCaseToSnakeCase is also enabled.
     * 
     * @param bool $enabled Whether to return validated data in snake_case (default: false)
     */
    public static function returnValidatedDataAsSnakeCase(bool $enabled = true): void {
        static::$returnValidatedDataAsSnakeCase = $enabled;
    }

    /**
     * Check if validated data should be returned in snake_case format.
     */
    public static function isReturnValidatedDataAsSnakeCaseEnabled(): bool {
        return static::$returnValidatedDataAsSnakeCase;
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

            // Convert validated data keys back to camelCase if conversion was enabled
            // UNLESS the user wants to keep it in snake_case for database operations
            if (static::$convertCamelCaseToSnakeCase && !empty($this->camelToSnakeMap)) {
                if (!static::$returnValidatedDataAsSnakeCase) {
                    // Default behavior: convert back to camelCase for Livewire component
                    $validated = $this->convertValidatedDataToCamelCase($validated);
                }
                // If returnValidatedDataAsSnakeCase is true, keep validated data in snake_case
            }

            // Mirror Livewire's built-in validation behavior: clear old errors on success.
            $this->livewireComponent->resetErrorBag();

            // Keep Livewire state in sync with any prepared/mutated values.
            // Only fill if data was converted back to camelCase
            if (!static::$returnValidatedDataAsSnakeCase || !static::$convertCamelCaseToSnakeCase) {
                $this->livewireComponent->fill($validated);
            } else {
                // If data is in snake_case, convert it to camelCase just for filling the component
                $camelCaseData = $this->convertValidatedDataToCamelCase($validated);
                $this->livewireComponent->fill($camelCaseData);
            }

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

        // Convert camelCase keys to snake_case if enabled
        if (static::$convertCamelCaseToSnakeCase) {
            $this->livewireData = $this->convertKeysToSnakeCase($this->livewireData);
        }

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

    /**
     * Convert array keys from camelCase to snake_case and track the mapping.
     *
     * @param array $data The data array with camelCase keys
     * @return array The data array with snake_case keys
     */
    protected function convertKeysToSnakeCase(array $data): array {
        $converted = [];
        
        foreach ($data as $key => $value) {
            $snakeKey = Str::snake($key);
            
            // Track the mapping from snake_case to camelCase
            if ($snakeKey !== $key) {
                $this->camelToSnakeMap[$snakeKey] = $key;
            }
            
            // Recursively convert nested arrays
            if (is_array($value)) {
                $value = $this->convertKeysToSnakeCase($value);
            }
            
            $converted[$snakeKey] = $value;
        }
        
        return $converted;
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
            // Convert validation error keys back to camelCase if conversion was enabled
            if (static::$convertCamelCaseToSnakeCase && !empty($this->camelToSnakeMap)) {
                $this->convertValidationErrorsToCamelCase($validator);
            }
            
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
     * Convert validation error keys from snake_case back to camelCase.
     * This ensures error messages reference the original property names in the Livewire component.
     */
    protected function convertValidationErrorsToCamelCase(Validator $validator): void {
        $errors = $validator->errors();
        $messages = $errors->messages();
        $convertedMessages = [];
        $keysToRemove = [];
        
        foreach ($messages as $snakeKey => $errorMessages) {
            $camelKey = $snakeKey;
            
            // Handle nested keys (e.g., 'user_info.first_name' -> 'userInfo.firstName')
            if (str_contains($snakeKey, '.')) {
                $parts = explode('.', $snakeKey);
                $convertedParts = [];
                
                foreach ($parts as $part) {
                    $convertedParts[] = $this->camelToSnakeMap[$part] ?? $part;
                }
                
                $camelKey = implode('.', $convertedParts);
            } else {
                // Check if this key was converted from camelCase
                $camelKey = $this->camelToSnakeMap[$snakeKey] ?? $snakeKey;
            }
            
            $convertedMessages[$camelKey] = $errorMessages;
            
            // Track keys to remove if they were converted
            if ($camelKey !== $snakeKey) {
                $keysToRemove[] = $snakeKey;
            }
        }
        
        // Replace the validator's error messages
        $errors->merge($convertedMessages);
        
        // Remove the snake_case keys that were converted
        foreach ($keysToRemove as $snakeKey) {
            $errors->forget($snakeKey);
        }
    }

    /**
     * Convert validated data keys from snake_case back to camelCase.
     * This ensures the data can be properly filled back into the Livewire component.
     */
    protected function convertValidatedDataToCamelCase(array $data): array {
        $converted = [];
        
        foreach ($data as $snakeKey => $value) {
            // Get the original camelCase key
            $camelKey = $this->camelToSnakeMap[$snakeKey] ?? $snakeKey;
            
            // Recursively convert nested arrays
            if (is_array($value)) {
                $value = $this->convertValidatedDataToCamelCase($value);
            }
            
            $converted[$camelKey] = $value;
        }
        
        return $converted;
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
