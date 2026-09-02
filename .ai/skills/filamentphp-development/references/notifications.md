# Notifications

## Contents

- Introduction
- Sending flash notifications
- Notification anatomy
- Notification actions
- Notification status types
- Customizing position and alignment
- Database notifications
- Broadcast notifications
- Global settings

## Introduction

Filament Notifications provide in-app feedback for actions, errors, and system events. They are session-flashed by default, so they can be sent from anywhere in your code (PHP or JavaScript), not just Livewire components. The `Notification` class lives in `Filament\Notifications`.

## Sending flash notifications

```php
use Filament\Notifications\Notification;

Notification::make()
  ->title('Saved successfully')
  ->success()
  ->send();
```

Flash notifications appear immediately via the session and disappear after a timeout.

### Sending from JavaScript

```js
new FilamentNotification()
  .title('Saved successfully')
  .success()
  .send()
```

## Notification anatomy

```php
Notification::make()
  ->title('Order shipped')
  ->body('Your order #1234 has been shipped.')
  ->icon('heroicon-o-truck')
  ->iconColor('success')
  ->duration(5000)        // milliseconds, null = persistent
  ->persistent()          // stays until dismissed
  ->send();
```

### Key methods

| Method           | Purpose                                        |
|------------------|------------------------------------------------|
| `->title()`      | Notification heading (required)                |
| `->body()`       | Additional description text                    |
| `->icon()`       | Heroicon identifier                            |
| `->iconColor()`  | Color for the icon                             |
| `->color()`      | Overall notification color                     |
| `->duration()`   | Auto-dismiss time in milliseconds              |
| `->persistent()` | Do not auto-dismiss                            |
| `->send()`       | Dispatch the notification                      |

## Notification actions

Notifications can include interactive action buttons:

```php
use Filament\Actions\Action;

Notification::make()
  ->title('Saved successfully')
  ->success()
  ->body('Changes to the post have been saved.')
  ->actions([
    Action::make('view')
      ->button()
      ->url(route('posts.show', $post), shouldOpenInNewTab: true),
    Action::make('undo')
      ->color('gray')
      ->dispatch('undoEditingPost', [$post->id]),
  ])
  ->send();
```

### Action methods in notifications

| Method                | Purpose                                    |
|-----------------------|--------------------------------------------|
| `->button()`          | Render as a button (default is link)       |
| `->url()`             | Navigate to URL                            |
| `->dispatch()`        | Dispatch a Livewire event                  |
| `->dispatchSelf()`    | Dispatch event to self                     |
| `->dispatchTo()`      | Dispatch event to another component        |
| `->close()`           | Close the notification                     |
| `->markAsRead()`      | Mark database notification as read         |
| `->markAsUnread()`    | Mark database notification as unread       |

## Notification status types

```php
Notification::make()->success()->title('Saved')->send();
Notification::make()->danger()->title('Error occurred')->send();
Notification::make()->warning()->title('Caution')->send();
Notification::make()->info()->title('Heads up')->send();
```

## Customizing position and alignment

```php
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\VerticalAlignment;

Notifications::alignment(Alignment::Start);
Notifications::verticalAlignment(VerticalAlignment::End);
```

## Database notifications

Database notifications persist in the `notifications` table and appear in a slide-over modal.

### Setup

1. Create the notifications table:

```bash
php artisan make:notifications-table
php artisan migrate
```

2. Ensure the `data` column uses `json()` (not `text()`).

3. Enable in your panel provider:

```php
use Filament\Panel;

public function panel(Panel $panel): Panel
{
  return $panel
    ->databaseNotifications();
}
```

### Sending database notifications

```php
use Filament\Notifications\Notification;

$recipient = auth()->user();

Notification::make()
  ->title('Saved successfully')
  ->sendToDatabase($recipient);
```

Or via Laravel's notifiable interface:

```php
$recipient->notify(
  Notification::make()
    ->title('Saved successfully')
    ->toDatabase(),
);
```

### Sidebar position

```php
use Filament\Enums\DatabaseNotificationsPosition;

$panel->databaseNotifications(
  position: DatabaseNotificationsPosition::Sidebar,
);
```

### Polling

```php
$panel
  ->databaseNotifications()
  ->databaseNotificationsPolling('30s');

// Disable polling:
$panel
  ->databaseNotifications()
  ->databaseNotificationsPolling(null);
```

### Echo / websockets

For real-time notifications, dispatch the `DatabaseNotificationsSent` event:

```php
Notification::make()
  ->title('New message')
  ->sendToDatabase($recipient, isEventDispatched: true);
```

### Marking as read

```php
Action::make('view')
  ->button()
  ->markAsRead(),
```

## Broadcast notifications

Broadcast notifications use Laravel's broadcasting system (e.g., Pusher, Ably, Reverb):

```php
Notification::make()
  ->title('New order received')
  ->broadcast($recipient);
```

Or via the notifiable interface:

```php
$recipient->notify(
  Notification::make()
    ->title('New order received')
    ->toBroadcast(),
);
```

### Setup

Requires Laravel Echo on the frontend and a broadcast driver configured in your app.

## Common pitfalls

- Forgetting to run `make:notifications-table` before using database notifications
- Using `text()` instead of `json()` for the `data` column in the notifications migration
- Not enabling `->databaseNotifications()` in the panel provider
- Sending database notifications without a proper notifiable model (must use `HasDatabaseNotifications` or Laravel's `Notifiable` trait)
- Forgetting that flash notifications use the session — they won't work in queued jobs (use database notifications instead)
