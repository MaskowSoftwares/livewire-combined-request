# Livewire Combined Request

Shared FormRequest base that you can use in both classic HTTP controllers and Livewire v3 components without duplicating validation logic.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12
- Livewire 3

## Installation

```bash
composer require maskow/livewire-combined-request
```

No configuration or manual service provider registration is required.

## Usage

### 1) Create a reusable request

```php
<?php

namespace App\Http\Requests;

use Maskow\CombinedRequest\CombinedFormRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Validator;

class ProfileRequest extends CombinedFormRequest
{
    public function authorize(): bool|Response
    {
        return Auth::check()
            ? Response::allow()
            : Response::deny('Please login, dude!');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower((string) $this->email),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (strtolower((string) $this->name) === 'john doe') {
                $validator->errors()->add('name', 'Who the hell are you?');
            }
        });
    }

    public function attributes(): array
    {
        return [
            'email' => 'email address',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Please provide an email.',
        ];
    }
}
```

You can keep using `prepareForValidation`, `messages`, `attributes`, and `passedValidation` as usual—these hooks run for both HTTP and Livewire flows.

### 2) Use it in a controller

```php
use App\Http\Requests\ProfileRequest;

class ProfileController {
    public function update(ProfileRequest $request) {
        $data = $request->validated();

        // ...
    }
}
```

### 3) Use the same request in a Livewire component

```php
use App\Http\Requests\ProfileRequest;
use Livewire\Component;

class ProfileForm extends Component {
    public string $name = '';
    public string $email = '';

    public function save(): void {
        $data = ProfileRequest::validateLivewire($this);

        // $data now contains the validated payload, and the component
        // was filled with any mutated values from prepareForValidation.
    }
}
```


### Handling authorization

Authorization runs in both contexts. In Livewire, authorization failures throw a validation exception on the `authorization` key. You can register a notifier (e.g., to flash a message) once during boot:

```php
use Maskow\CombinedRequest\CombinedFormRequest;

CombinedFormRequest::notifyAuthorizationUsing(function ($component, string $message) {
    $component->addError('authorization', $message);
    // Log::warning('Authorization failed!');
    // $component->js('alert("Authorization failed!")');
});
```

## How it works (under the hood)

- `ProfileRequest::validateLivewire($this)` builds a fake HTTP request from the component (`fromLivewire`), wiring the service container and redirector so the normal FormRequest pipeline can run.
- The component’s public properties are pulled into the request (`prepareLivewireValidationData`), files are split out, values are normalized for Symfony’s `InputBag`, and your `prepareForValidation` hook runs so data can be mutated first.
- Authorization is executed via your `authorize` method; denials are converted into a `ValidationException` on the `authorization` key (and optionally sent to your notifier).
- The usual validator is created (`getValidatorInstance`), `withValidator` callbacks run, and on success the component’s error bag is cleared and the validated/mutated data is written back to the component via `fill`.
- `validationData()` is overridden to feed the prepared Livewire payload to the validator, and `validated()` ensures validation is triggered even if you call it directly on the request.


## License

Licensed under the Apache 2.0 license. See `LICENSE` for details.
