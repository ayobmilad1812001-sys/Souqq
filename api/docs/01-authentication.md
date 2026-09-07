# 01 — Authentication

Token-based authentication with Laravel Sanctum. No sessions, no cookies, no CSRF dance — this is a pure API.

## Files

| File | Role |
| --- | --- |
| `app/Http/Controllers/Api/V1/AuthController.php` | register / login / logout / user |
| `app/Http/Requests/Auth/RegisterRequest.php` | Registration rules |
| `app/Http/Requests/Auth/LoginRequest.php` | Login rules |
| `app/Http/Resources/UserResource.php` | User output shape |
| `app/Models/User.php` | `HasApiTokens`, `hashed` cast, role cast |
| `config/sanctum.php` | Token configuration |
| `tests/Feature/AuthenticationTest.php` | 12 tests |

## Endpoints

| Method | Endpoint | Auth | Throttle |
| --- | --- | --- | --- |
| `POST` | `/api/v1/register` | — | 6/min |
| `POST` | `/api/v1/login` | — | 6/min |
| `POST` | `/api/v1/logout` | ✅ | 120/min |
| `GET` | `/api/v1/user` | ✅ | 120/min |

### Register

```http
POST /api/v1/register
Content-Type: application/json

{
  "name": "Amina Saleh",
  "email": "amina@example.com",
  "password": "Str0ngPassword",
  "password_confirmation": "Str0ngPassword",
  "role": "customer"
}
```

```json
{
  "success": true,
  "data": {
    "user": { "id": 1, "name": "Amina Saleh", "email": "amina@example.com", "role": "customer" },
    "token": "1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  },
  "message": "Registration successful."
}
```

### Using the token

```http
Authorization: Bearer 1|xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

## Design decisions

### 1. `role` is whitelisted, not free text

```php
'role' => ['sometimes', Rule::in([UserRole::Customer->value, UserRole::Seller->value])],
```

Self-registration may produce a **customer** or a **seller**, never an **admin**.
Accepting `admin` here would be a one-line privilege-escalation hole: anyone
could POST `{"role": "admin"}` and own the platform. Admin accounts are
provisioned out of band (seeder, or a console command).

Tested by `a_visitor_cannot_register_as_an_admin`.

### 2. Passwords are hashed by the model, not the controller

```php
protected function casts(): array
{
    return ['password' => 'hashed', ...];
}
```

The `hashed` cast means a plaintext password cannot reach the database even if a
future caller forgets `Hash::make()`. The controller assigns the raw value and
the model handles it — one less thing to get wrong in one more place.

Tested by `registration_stores_a_hashed_password`.

### 3. Login does not reveal whether an account exists

```php
if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
    throw ValidationException::withMessages([
        'email' => ['These credentials do not match our records.'],
    ]);
}
```

A different message for "no such user" versus "wrong password" lets an attacker
enumerate which email addresses have accounts. One message covers both.

Tested by `login_does_not_reveal_whether_an_account_exists`, which asserts the
two responses are byte-identical.

### 4. Logout revokes only the current token

```php
$request->user()->currentAccessToken()->delete();
```

Signing out on a phone must not sign the user out of their laptop. Each device
gets its own token (labelled from `device_name` or the user agent), so tokens
can be revoked individually.

Tested by `logging_out_revokes_only_the_current_token`.

### 5. Aggressive throttling on credential endpoints

```php
RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(6)
    ->by($request->input('email').'|'.$request->ip()));
```

Six attempts per minute, keyed on **email + IP**. This is the primary defence
against password spraying. Keying on the email alone would let an attacker lock
a victim out; keying on IP alone would be trivially bypassed with a proxy pool.
Combining them limits both.

## Validation rules

| Field | Rules |
| --- | --- |
| `name` | required, string, max 255 |
| `email` | required, valid email, max 255, unique |
| `password` | required, confirmed, min 8, mixed case, contains a number |
| `role` | optional, `customer` or `seller` only |

Password strength comes from `Password::defaults()->min(8)->mixedCase()->numbers()`,
so tightening the policy platform-wide is a one-line change.

## Error responses

| Situation | Status | Body |
| --- | --- | --- |
| Missing / weak fields | 422 | `{"success": false, "message": "Validation failed.", "errors": {...}}` |
| Wrong credentials | 422 | `errors.email: ["These credentials do not match our records."]` |
| No / invalid token | 401 | `{"success": false, "message": "Unauthenticated."}` |
| Too many attempts | 429 | `{"success": false, "message": "Too many requests. Please slow down."}` |

The 401 shape is guaranteed by `ForceJsonResponse` middleware, which rewrites the
`Accept` header so a client that forgets it still gets JSON instead of an HTML
error page or a redirect to a login route that does not exist.

## Tests

```
✓ a visitor can register and receives a token
✓ registration stores a hashed password
✓ a visitor may register as a seller
✓ a visitor cannot register as an admin
✓ registration validation uses the documented error envelope
✓ registration rejects a duplicate email
✓ a user can log in with valid credentials
✓ login fails with a wrong password
✓ login does not reveal whether an account exists
✓ an authenticated user can read their profile
✓ guests are rejected with the error envelope
✓ logging out revokes only the current token
```
