# Livewire Combined Request

A powerful Laravel FormRequest base class that seamlessly works in both HTTP controllers and Livewire v3 components. Write your validation rules, authorization logic, and parameter requirements once—use them everywhere. Perfect for Laravel 10/11/12 projects that want to eliminate duplicated validation between APIs and Livewire components.

## Features

- 🔄 **Unified API**: One FormRequest for HTTP controllers, APIs, and Livewire components
- 🔒 **Parameter Requirements**: Declare required parameters that are automatically validated
- 🛡️ **Authorization**: Identical authorization logic across all contexts
- 📁 **File Uploads**: Full support for Livewire file uploads and temporary files
- 🎯 **Parameter Binding**: Elegant parameter system that works with route model binding and manual injection
- 🚫 **Zero Configuration**: Drop it into any Laravel + Livewire 3 app

## Why use this?

Stop writing validation rules twice! Whether you're building an API endpoint or a Livewire component, use the same FormRequest with identical rules, authorization, and parameter handling.

**Before:**
```php
// API Controller
class UpdateTeamRequest extends FormRequest { /* rules here */ }

// Livewire Component  
public function save() {
    $this->validate([ /* same rules again! */ ]);
    // Manual authorization check...
    // Manual parameter handling...
}
```

**After:**
```php
// One request class for everything
class UpdateTeamRequest extends CombinedFormRequest {
    protected array $requiredParameters = ['team', 'workspace'];
    
    public function authorize() { /* works everywhere */ }
    public function rules() { /* works everywhere */ }
}

// API Controller
public function update(UpdateTeamRequest $request, Team $team) { /* automatic */ }

// Livewire Component
public function save() {
    $validated = UpdateTeamRequest::validateLivewire($this, [
        'team' => $this->team,
        'workspace' => $this->workspace
    ]);
}
```

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

### 1) Create a request with required parameters

```php
<?php

namespace App\Http\Requests;

use Maskow\CombinedRequest\CombinedFormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTeamRequest extends CombinedFormRequest
{
    /**
     * Define which parameters this request requires.
     * These will be automatically validated when the request is created.
     */
    protected array $requiredParameters = [
        'team',        // The team model/object
        'workspace',   // The workspace model/object
    ];

    public function authorize(): bool
    {
        // Use parameter() to access both route parameters (HTTP) and injected parameters (Livewire)
        $team = $this->parameter('team');
        $workspace = $this->parameter('workspace');
        
        return Gate::allows('update', $team) && 
               $this->user()->can('access', $workspace);
    }

    public function rules(): array
    {
        $team = $this->parameter('team');
        
        return [
            'name' => ['required', 'string', 'max:255', 'unique:teams,name,' . $team?->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_public' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A team with this name already exists.',
            'name.required' => 'Team name is required.',
        ];
    }
}
```

### 2) Use it in HTTP controllers and APIs

The request works exactly like a normal Laravel FormRequest with automatic route model binding:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\Workspace;

class TeamController extends Controller
{
    /**
     * Route: PUT /workspaces/{workspace}/teams/{team}
     */
    public function update(UpdateTeamRequest $request, Workspace $workspace, Team $team)
    {
        // Required parameters are automatically satisfied by route model binding
        // $request->parameter('team') === $team
        // $request->parameter('workspace') === $workspace
        
        $validated = $request->validated();
        $team->update($validated);
        
        return response()->json($team);
    }
}
```

**Route definition:**
```php
// routes/api.php
Route::middleware('auth:sanctum')->group(function () {
    Route::put('/workspaces/{workspace}/teams/{team}', [TeamController::class, 'update']);
});
```

### 3) Use the same request in Livewire components

```php
<?php

namespace App\Livewire;

use App\Http\Requests\UpdateTeamRequest;
use App\Models\Team;
use App\Models\Workspace;
use Livewire\Component;

class EditTeamForm extends Component
{
    public Team $team;
    public Workspace $workspace;
    
    // Public properties for the form
    public string $name = '';
    public string $description = '';
    public bool $is_public = false;

    public function mount(Team $team, Workspace $workspace)
    {
        $this->team = $team;
        $this->workspace = $workspace;
        $this->name = $team->name;
        $this->description = $team->description ?? '';
        $this->is_public = $team->is_public;
    }

    public function save()
    {
        try {
            // The same validation rules and authorization logic!
            $validated = UpdateTeamRequest::validateLivewire($this, [
                'team' => $this->team,
                'workspace' => $this->workspace,
            ]);

            $this->team->update($validated);
            
            session()->flash('message', 'Team updated successfully!');
            
        } catch (\InvalidArgumentException $e) {
            // Missing required parameters
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.edit-team-form');
    }
}
```

## Parameter System

The package provides a powerful parameter system that works seamlessly across HTTP and Livewire contexts:

### Required Parameters

Define required parameters in your request class:

```php
class CreateProjectRequest extends CombinedFormRequest
{
    protected array $requiredParameters = [
        'workspace',    // Model/Object
        'team',         // Model/Object  
        'user_id',      // Primitive value
        'template_id',  // Optional: can be null
    ];

    public function authorize()
    {
        $workspace = $this->parameter('workspace');
        $team = $this->parameter('team');
        $userId = $this->parameter('user_id');
        
        return $this->user()->can('createProject', [$workspace, $team]) &&
               $this->user()->id === $userId;
    }
}
```

### Parameter Access

Use the unified `parameter()` method to access parameters in both contexts:

```php
// Works in both HTTP and Livewire contexts
$team = $this->parameter('team');
$workspace = $this->parameter('workspace');
$userId = $this->parameter('user_id', auth()->id()); // with default

// Check if parameter exists
if ($this->hasParameter('optional_param')) {
    // ...
}

// Get all parameters
$allParams = $this->parameters();
```

### HTTP Context (Route Model Binding)

Parameters are automatically resolved from route parameters:

```php
// Route: PUT /workspaces/{workspace}/teams/{team}
// Parameters 'workspace' and 'team' are automatically available via route model binding
```

### Livewire Context (Manual Injection)

Pass parameters when calling the validation:

```php
// In your Livewire component
public function save()
{
    $validated = CreateProjectRequest::validateLivewire($this, [
        'workspace' => $this->workspace,
        'team' => $this->selectedTeam,
        'user_id' => auth()->id(),
        'template_id' => $this->selectedTemplate?->id,
    ]);
}
```

### Error Handling

Missing required parameters throw descriptive exceptions:

```php
// Exception message:
// "Missing required parameters for App\Http\Requests\UpdateTeamRequest: team, workspace. 
//  Please provide these parameters when calling fromLivewire() or ensure they exist in the route."
```

## How it works (under the hood)

- `ProfileRequest::validateLivewire($this)` builds a fake HTTP request from the component (`fromLivewire`), wiring the service container and redirector so the normal FormRequest pipeline can run.
- The component’s public properties are pulled into the request (`prepareLivewireValidationData`), files are split out, values are normalized for Symfony’s `InputBag`, and your `prepareForValidation` hook runs so data can be mutated first.
- Authorization is executed via your `authorize` method; denials are converted into a `ValidationException` on the `authorization` key (and optionally sent to your notifier).
- The usual validator is created (`getValidatorInstance`), `withValidator` callbacks run, and on success the component’s error bag is cleared and the validated/mutated data is written back to the component via `fill`.
- `validationData()` is overridden to feed the prepared Livewire payload to the validator, and `validated()` ensures validation is triggered even if you call it directly on the request.

## ⚠️ Important Notes & Common Pitfalls

### Public Livewire Properties vs. Required Parameters

**Critical:** When using models as public properties in your Livewire component, be aware that they are automatically serialized (converted to strings/arrays/IDs) by Livewire. This can cause issues when your FormRequest expects the actual model object.

**Example of the problem:**

```php
class EditTeamComponent extends Component
{
    public Workspace $workspace; // Public property - will be serialized!
    
    public function save()
    {
        // ❌ WRONG: This will fail because $this->workspace is serialized
        $validated = UpdateTeamRequest::validateLivewire($this);
        
        // The request's authorize() method might fail because:
        // $this->parameter('workspace') returns a string/ID, not the model object!
    }
}
```

**The solution:**

```php
class EditTeamComponent extends Component
{
    public Workspace $workspace; // Public property for display purposes
    
    public function save()
    {
        // ✅ CORRECT: Always pass model objects explicitly as parameters
        $validated = UpdateTeamRequest::validateLivewire($this, [
            'workspace' => $this->workspace, // Pass the actual model object
        ]);
        
        // Now $this->parameter('workspace') in your request will be the actual model
    }
}
```

**Why this matters:**

- Your `authorize()` method likely expects actual model objects to check permissions
- Gates and policies expect models, not serialized representations
- Missing this will cause authorization to fail silently or throw unexpected errors
- The required parameter validation won't catch this because the property exists (but is wrong type)

**Best practice:** Always pass model objects explicitly in the parameters array, even if they exist as public properties on your component.

## FAQ

### General Questions

**Q: Does it work with file uploads in Livewire?**
A: Yes! Use `WithFileUploads` trait in your component. The request receives `TemporaryUploadedFile` instances and all file validation rules work as expected.

**Q: Can I use it in API controllers?**
A: Absolutely! Type-hint your request in any controller (web or API). It behaves exactly like a normal Laravel FormRequest.

**Q: Do FormRequest hooks like `prepareForValidation` work?**
A: Yes! All standard hooks (`prepareForValidation`, `withValidator`, `messages`, `attributes`, `passedValidation`) work identically in both contexts.

**Q: How do missing required parameters behave?**
A: They throw an `InvalidArgumentException` with a descriptive message listing exactly which parameters are missing.

### Authorization

**Q: How do I handle authorization failures in Livewire?**
A: Register a global notifier via `CombinedFormRequest::notifyAuthorizationUsing(...)` to handle authorization failures (e.g., show toast, flash message).

**Q: Does authorization work the same way in both contexts?**
A: Yes! Your `authorize()` method runs identically. In HTTP it returns 403, in Livewire it throws a validation exception.

### Parameters

**Q: What's the difference between `route()` and `parameter()`?**
A: `parameter()` is the new unified method that works in both contexts. `route()` still works for backward compatibility but internally calls `parameter()`.

**Q: Can I mix route model binding with manual parameters?**
A: Yes! HTTP requests use route model binding, Livewire uses manual injection. Both are accessed via the same `parameter()` method.

**Q: What types of values can be parameters?**
A: Anything! Models, primitive values, arrays, objects—the parameter system is completely flexible.

## How it works

Under the hood, the package creates a fake HTTP request from your Livewire component, enabling the standard FormRequest pipeline to run. Here's the flow:

1. **Request Creation**: `validateLivewire()` builds a request instance with the container and redirector
2. **Parameter Binding**: Required parameters are validated and bound to the request
3. **Data Preparation**: Component properties are extracted, files separated, and normalized for Symfony's InputBag
4. **Hooks Execution**: Your `prepareForValidation()` runs, allowing data mutation
5. **Authorization**: The `authorize()` method runs; failures become validation exceptions
6. **Validation**: Standard validator creation with `withValidator()` callbacks
7. **Success Handling**: Component errors are cleared and validated data is filled back via `fill()`

The `parameter()` method provides a unified API that checks request parameters (Livewire) first, then falls back to route parameters (HTTP).

## Testing

Run the test suite:

```bash
composer install
composer test
```

## License

Licensed under the Apache 2.0 license. See `LICENSE` for details.

## About

Built by Julius Maskow at [Software-Stratege.de](https://www.software-stratege.de).

Feedback and contributions welcome!
