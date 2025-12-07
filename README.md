# Livewire Combined Request

Shared Laravel FormRequest base that works in both classic HTTP controllers and Livewire v3 components—one set of validation rules for both flows, including authorization and file uploads. Perfect for Laravel 10 / 11 / 12 projects that want to avoid duplicated Livewire validation logic.

## Why use this?

- Reuse one FormRequest in controllers, APIs, and Livewire v3 components.
- Keep `rules`, `authorize`, `prepareForValidation`, `withValidator`, `messages`, and `attributes` in one place.
- First-class Livewire support: file uploads (`WithFileUploads` / temporary files), authorization, custom error bags.
- No config needed—drop it into any Laravel 10/11/12 + Livewire 3 app.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12
- Livewire 3

## Installation

```bash
composer require maskow/livewire-combined-request
```

No configuration or manual service provider registration is required.

## Quick start

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

### 2) Use it in a controller (HTTP/API)

```php
use App\Http\Requests\ProfileRequest;

class ProfileController {
    public function update(ProfileRequest $request) {
        $data = $request->validated();

        // ...
    }
}
```

### 3) Use the same request in a Livewire component (Livewire validation)

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

## FAQ

- **Does it work with Livewire file uploads and temporary files?** Yes—use `WithFileUploads`; the request receives `TemporaryUploadedFile` instances and the `file` rule works as expected.
- **Can I use it in API controllers?** Yes—type-hint your request in any controller (web or API); `validated()` returns the same data structure.
- **Do `prepareForValidation`, `withValidator`, `messages`, and `attributes` run in Livewire?** Yes—identical to HTTP FormRequests.
- **How do I handle authorization failures in Livewire?** Register a notifier via `CombinedFormRequest::notifyAuthorizationUsing(...)` if you want to display a custom message or toast.
- **How do I run the test suite?** `composer install` then `composer test`.

## JavaScript scroll animations (aos-lite)

This repository also ships a tiny, dependency-free animate-on-scroll helper inspired by AOS but tuned for Livewire/HTMX/Turbo-style DOM updates. The library auto-initializes when loaded in a browser, exposes a refresh hook for dynamic content, and ships both ESM and IIFE bundles plus a minified CSS file.

### Install

```bash
npm install aos-lite
```

### Usage

**Script tag:**

```html
<link rel="stylesheet" href="/dist/aos.css">
<script src="/dist/aos.iife.js"></script>

<div data-aos="fade-up">I will animate when visible</div>

<script>
  // already initialized automatically, but you can manually refresh after Livewire updates:
  window.AOSLite.refresh();
</script>
```

**ES modules:**

```ts
import { createAnimator } from "aos-lite";
import "aos-lite/dist/aos.css";

const animator = createAnimator({ once: false });
animator.init();

// Refresh after DOM mutations (e.g., Livewire partial updates)
animator.refresh();
```

The CSS honors optional `data-aos-duration`, `data-aos-delay`, and `data-aos-easing` attributes per element. Supported animation names include `fade`, `fade-up`, `fade-down`, `fade-left`, `fade-right`, `zoom-in`, `zoom-out`, `slide-up`, and `slide-down`.

## About

Built by Julius Maskow at [Software-Stratege.de](https://www.software-stratege.de).


## License

Licensed under the Apache 2.0 license. See `LICENSE` for details.
