# PHP Web Development Learning Guide

A practical learning path for JavaScript/React developers wanting to learn PHP MVC patterns.

---

## Prerequisites

You should already know:
- JavaScript (ES6+)
- React or React Native
- Basic understanding of web development (HTTP, REST, etc.)

---

## Phase 1: PHP Basics (1-2 days)

### Syntax Comparison: JS vs PHP

| JavaScript | PHP |
|------------|-----|
| `const x = 5` | `$x = 5;` |
| `let arr = []` | `$arr = [];` |
| `obj.property` | `$obj->property` |
| `obj.method()` | `$obj->method()` |
| `arr.map(fn)` | `array_map($fn, $arr)` |
| `arr.filter(fn)` | `array_filter($arr, $fn)` |
| `arr.forEach(fn)` | `foreach ($arr as $item) {}` |
| `async/await` | N/A (PHP is synchronous) |
| `import X from 'y'` | `use Namespace\X;` |
| `export class X` | `class X {}` (auto-exported) |
| `class X extends Y` | `class X extends Y` (same!) |
| `constructor()` | `__construct()` |
| `this.prop` | `$this->prop` |
| Template literals | Heredoc/Nowdoc or concatenation |
| `===` (strict equal) | `===` (same!) |
| `null ?? default` | `$var ?? $default` (same!) |

### PHP-Specific Concepts

```php
<?php
// 1. Variables always start with $
$name = "John";
$age = 30;

// 2. Arrays (associative = JS objects)
$user = [
    'name' => 'John',
    'age' => 30
];
echo $user['name'];

// 3. String concatenation uses .
$greeting = "Hello, " . $name;

// 4. Double quotes interpolate, single don't
$message = "Hello, $name";     // Works
$message = 'Hello, $name';     // Literal $name

// 5. Functions
function greet($name) {
    return "Hello, $name";
}

// 6. Arrow functions (PHP 7.4+)
$double = fn($x) => $x * 2;

// 7. Classes
class User {
    private string $name;
    
    public function __construct(string $name) {
        $this->name = $name;
    }
    
    public function getName(): string {
        return $this->name;
    }
}

// 8. Namespaces
namespace App\Models;

use App\Core\Database;

class UserModel {
    // ...
}
```

### Quick Start Resources

- [PHP The Right Way](https://phptherightway.com/) - Modern PHP best practices
- [Learn X in Y Minutes: PHP](https://learnxinyminutes.com/docs/php/) - Quick syntax reference
- [PHP Official Documentation](https://www.php.net/manual/en/) - Comprehensive reference

---

## Phase 2: Understand Kanboard's Architecture (1-2 days)

### Directory Structure

```
kanboard/
├── index.php                    # Entry point
├── app/
│   ├── common.php               # Bootstrap, DI container
│   ├── Controller/              # Handle HTTP requests
│   │   ├── BaseController.php   # Base class for all controllers
│   │   ├── TaskController.php   # Task-related actions
│   │   └── ...
│   ├── Model/                   # Database interactions
│   │   ├── Base.php             # Base model class
│   │   ├── TaskModel.php        # Task data operations
│   │   └── ...
│   ├── Template/                # View templates (PHP + HTML)
│   │   ├── layout.php           # Main layout
│   │   ├── task/                # Task-related views
│   │   └── ...
│   ├── Core/                    # Framework core classes
│   │   ├── Database.php         # Database connection
│   │   ├── Http/                # Request/Response handling
│   │   └── ...
│   ├── ServiceProvider/         # Dependency injection setup
│   │   ├── DatabaseProvider.php
│   │   ├── RouteProvider.php
│   │   └── ...
│   └── Middleware/              # Request/response pipeline
├── assets/                      # CSS, JS, images
├── data/                        # SQLite database, uploads
└── vendor/                      # Composer dependencies
```

### Study Files in This Order

1. **`index.php`** - Entry point, see how request starts
2. **`app/common.php`** - Bootstrap, DI container setup
3. **`app/ServiceProvider/*.php`** - How services are registered
4. **`app/Core/Controller/Runner.php`** - How requests are routed
5. **`app/Controller/BaseController.php`** - Base controller pattern
6. **`app/Model/Base.php`** - Base model pattern
7. **`app/Template/layout.php`** - View templates

### Key Patterns in Kanboard

#### 1. Dependency Injection (Pimple Container)

```php
// Services are registered in container
$container['db'] = function($c) {
    return new Database($c['config']);
};

// And accessed anywhere via $this->
$this->db->table('tasks')->findAll();
```

#### 2. Service Providers

```php
class DatabaseProvider implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container['db'] = function($c) {
            return new Database();
        };
    }
}
```

#### 3. Controller Pattern

```php
class TaskController extends BaseController
{
    public function show()
    {
        // 1. Get data from model
        $task = $this->taskModel->getById($id);
        
        // 2. Render template with data
        $this->response->html(
            $this->template->render('task/show', [
                'task' => $task
            ])
        );
    }
}
```

#### 4. Model Pattern

```php
class TaskModel extends Base
{
    const TABLE = 'tasks';
    
    public function getById($id)
    {
        return $this->db
            ->table(self::TABLE)
            ->eq('id', $id)
            ->findOne();
    }
    
    public function create(array $values)
    {
        return $this->db
            ->table(self::TABLE)
            ->persist($values);
    }
}
```

#### 5. Template Pattern

```php
<!-- app/Template/task/show.php -->
<div class="task-details">
    <h1><?= $this->text->e($task['title']) ?></h1>
    <p><?= $this->text->markdown($task['description']) ?></p>
    
    <?php if ($task['date_due']): ?>
        <span>Due: <?= $this->dt->date($task['date_due']) ?></span>
    <?php endif ?>
</div>
```

---

## Phase 3: Hands-On Practice (3-5 days)

### Exercise 1: Trace a Request

Add debug logging to understand the flow:

```php
// In index.php, add after router dispatch:
error_log("=== Request Debug ===");
error_log("Controller: " . $container['router']->getController());
error_log("Action: " . $container['router']->getAction());
error_log("Params: " . print_r($_GET, true));
```

Then access different pages and check your PHP error log.

### Exercise 2: Create a Simple Feature

Try adding a "task notes" feature:

1. **Add migration** in `app/Schema/Sqlite.php`:
```php
function version_XXX(PDO $pdo)
{
    $pdo->exec('
        CREATE TABLE task_notes (
            id INTEGER PRIMARY KEY,
            task_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            date_creation INTEGER NOT NULL,
            FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
        )
    ');
}
```

2. **Create Model** `app/Model/TaskNoteModel.php`:
```php
<?php
namespace Kanboard\Model;

class TaskNoteModel extends Base
{
    const TABLE = 'task_notes';
    
    public function getAll($taskId)
    {
        return $this->db->table(self::TABLE)
            ->eq('task_id', $taskId)
            ->desc('date_creation')
            ->findAll();
    }
    
    public function create($taskId, $content)
    {
        return $this->db->table(self::TABLE)->persist([
            'task_id' => $taskId,
            'content' => $content,
            'date_creation' => time(),
        ]);
    }
}
```

3. **Create Controller** `app/Controller/TaskNoteController.php`:
```php
<?php
namespace Kanboard\Controller;

class TaskNoteController extends BaseController
{
    public function create()
    {
        $task = $this->getTask();
        $content = $this->request->getValue('content');
        
        if (!empty($content)) {
            $this->taskNoteModel->create($task['id'], $content);
            $this->flash->success(t('Note added successfully.'));
        }
        
        $this->response->redirect(
            $this->helper->url->to('TaskViewController', 'show', ['task_id' => $task['id']])
        );
    }
}
```

4. **Create Template** `app/Template/task_note/show.php`:
```php
<div class="task-notes">
    <h3><?= t('Notes') ?></h3>
    
    <?php foreach ($notes as $note): ?>
        <div class="note">
            <p><?= $this->text->e($note['content']) ?></p>
            <small><?= $this->dt->datetime($note['date_creation']) ?></small>
        </div>
    <?php endforeach ?>
    
    <form method="post" action="<?= $this->url->href('TaskNoteController', 'create', ['task_id' => $task['id']]) ?>">
        <?= $this->form->csrf() ?>
        <?= $this->form->textarea('content', [], []) ?>
        <?= $this->form->submit(t('Add Note')) ?>
    </form>
</div>
```

5. **Register Model** in `app/ServiceProvider/ClassProvider.php`
6. **Add Route** in `app/ServiceProvider/RouteProvider.php`

### Exercise 3: Understand the Query Builder

Kanboard uses PicoDb - a lightweight query builder:

```php
// Simple select
$this->db->table('tasks')->findAll();

// With conditions
$this->db->table('tasks')
    ->columns('id', 'title', 'date_due')
    ->eq('project_id', 1)
    ->gt('date_due', time())
    ->isNotNull('owner_id')
    ->orderBy('priority', 'DESC')
    ->limit(10)
    ->findAll();

// Equivalent SQL:
// SELECT id, title, date_due FROM tasks 
// WHERE project_id = 1 
//   AND date_due > 1234567890
//   AND owner_id IS NOT NULL
// ORDER BY priority DESC
// LIMIT 10

// Joins
$this->db->table('tasks')
    ->join('users', 'id', 'owner_id')
    ->columns('tasks.title', 'users.username')
    ->findAll();

// Insert
$this->db->table('tasks')->persist([
    'title' => 'New Task',
    'project_id' => 1,
]);

// Update
$this->db->table('tasks')
    ->eq('id', 123)
    ->update(['title' => 'Updated Title']);

// Delete
$this->db->table('tasks')
    ->eq('id', 123)
    ->remove();
```

---

## Phase 4: Key PHP Concepts to Master

| Concept | What to Learn | Kanboard Example |
|---------|---------------|------------------|
| **Namespaces** | Organize code, avoid conflicts | `namespace Kanboard\Model;` |
| **Autoloading** | PSR-4 standard | See `composer.json` |
| **PDO** | Database abstraction | Via PicoDb wrapper |
| **Sessions** | Server-side state | `app/Core/Session/` |
| **Middleware** | Request/response pipeline | `app/Middleware/` |
| **Dependency Injection** | Decoupled components | Pimple container |
| **Traits** | Reusable methods | Limited use in Kanboard |
| **Interfaces** | Contracts for classes | `*Interface.php` files |

### Sessions in PHP

```php
// Start session (done in bootstrap)
session_start();

// Set value
$_SESSION['user_id'] = 123;

// Get value
$userId = $_SESSION['user_id'] ?? null;

// In Kanboard, use the session helper:
session_set('key', 'value');
$value = session_get('key');
session_exists('key');
session_remove('key');
```

### Error Handling

```php
// Try-catch (same as JS)
try {
    $result = $this->riskyOperation();
} catch (Exception $e) {
    $this->logger->error($e->getMessage());
    throw $e;
}

// Custom exceptions
class TaskNotFoundException extends Exception {}

throw new TaskNotFoundException('Task not found');
```

---

## Phase 5: Recommended Learning Resources

### Free Online Resources

1. **[Laracasts: PHP for Beginners](https://laracasts.com/series/php-for-beginners-2023-edition)** (Free)
   - Modern PHP, OOP, MVC concepts
   - Video-based, very practical

2. **[SymfonyCasts: PHP Fundamentals](https://symfonycasts.com/tracks/php)**
   - Deep dive into OOP and design patterns
   - Professional quality

3. **[PHP Documentation](https://www.php.net/manual/en/)**
   - Official reference
   - Excellent function reference with examples

4. **[PHP The Right Way](https://phptherightway.com/)**
   - Modern best practices
   - Community-maintained

### YouTube Channels

- **Traversy Media** - PHP crash courses
- **Program With Gio** - Modern PHP tutorials
- **The Codeholic** - Laravel & PHP tutorials

### Books

1. **"Modern PHP" by Josh Lockhart**
   - Updated practices for PHP 7/8
   - Great for transitioning developers

2. **"PHP Objects, Patterns, and Practice"**
   - Deep OOP dive
   - Design patterns in PHP

---

## Phase 6: Development Tools Setup

### Essential Tools

```bash
# Package manager (like npm)
composer

# Testing (like Jest)
phpunit

# Code formatting (like Prettier)
php-cs-fixer

# Debugging (like Chrome DevTools)
xdebug
```

### VS Code Extensions

- **PHP Intelephense** - IntelliSense, go to definition
- **PHP Debug** - Xdebug integration
- **PHP Namespace Resolver** - Auto-import classes
- **phpcs** - Code style checking

### Xdebug Setup (Highly Recommended!)

Add to your `php.ini`:

```ini
[xdebug]
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_port=9003
xdebug.client_host=127.0.0.1
```

VS Code `launch.json`:

```json
{
    "version": "0.2.0",
    "configurations": [
        {
            "name": "Listen for Xdebug",
            "type": "php",
            "request": "launch",
            "port": 9003
        }
    ]
}
```

Now you can set breakpoints and step through code!

---

## 📋 2-Week Learning Plan

| Days | Focus | Outcome |
|------|-------|---------|
| **1-2** | PHP syntax, OOP basics | Comfortable reading PHP code |
| **3-4** | Study Kanboard structure | Understand request flow |
| **5-7** | PicoDb, Models, Controllers | Can modify existing features |
| **8-10** | Create a small feature | End-to-end understanding |
| **11-14** | Templates, Forms, Security | Ready to contribute |

### Daily Practice

1. **Morning**: Read one Kanboard file thoroughly
2. **Afternoon**: Modify something small, test it
3. **Evening**: Watch one tutorial video

---

## Key Differences: React vs PHP MVC

| React (Client-Side) | PHP MVC (Server-Side) |
|---------------------|----------------------|
| SPA, client-side routing | Full page loads, server routing |
| State in components/Redux | State in session/database |
| Fetch API for data | Direct database queries |
| Virtual DOM, reactive updates | PHP generates HTML once |
| npm packages | Composer packages |
| Build step required | No build for PHP |
| Async everywhere | Synchronous (simpler!) |
| JSX templates | PHP mixed with HTML |

### Mental Model Shift

**React:**
```
User Action → State Change → Re-render Component → DOM Update
```

**PHP MVC:**
```
HTTP Request → Router → Controller → Model → Template → HTML Response
```

---

## Quick Reference: Kanboard Patterns

### Reading a Controller

```php
// app/Controller/TaskViewController.php
public function show()
{
    // 1. Get and validate data
    $task = $this->getTask();  // From BaseController
    
    // 2. Gather related data from models
    $subtasks = $this->subtaskModel->getAll($task['id']);
    $comments = $this->commentModel->getAll($task['id']);
    
    // 3. Render template with data
    $this->response->html(
        $this->helper->layout->task('task_view/show', [
            'task' => $task,
            'subtasks' => $subtasks,
            'comments' => $comments,
            'title' => $task['title'],
        ])
    );
}
```

### Reading a Model

```php
// app/Model/TaskModel.php
class TaskModel extends Base
{
    const TABLE = 'tasks';
    
    // Simple query
    public function getById($id)
    {
        return $this->db->table(self::TABLE)
            ->eq('id', $id)
            ->findOne();
    }
    
    // Complex query with joins
    public function getDetails($taskId)
    {
        return $this->db
            ->table(self::TABLE)
            ->columns(
                self::TABLE.'.*',
                'users.username AS assignee_name'
            )
            ->join('users', 'id', 'owner_id', self::TABLE)
            ->eq(self::TABLE.'.id', $taskId)
            ->findOne();
    }
}
```

### Reading a Template

```php
<!-- app/Template/task_view/show.php -->

<!-- Escape output to prevent XSS -->
<h1><?= $this->text->e($task['title']) ?></h1>

<!-- Conditional rendering -->
<?php if (!empty($task['description'])): ?>
    <div class="description">
        <?= $this->text->markdown($task['description']) ?>
    </div>
<?php endif ?>

<!-- Loops -->
<?php foreach ($subtasks as $subtask): ?>
    <div class="subtask">
        <?= $this->text->e($subtask['title']) ?>
    </div>
<?php endforeach ?>

<!-- Links -->
<a href="<?= $this->url->href('TaskController', 'edit', ['task_id' => $task['id']]) ?>">
    Edit
</a>

<!-- Include partials -->
<?= $this->render('task/sidebar', ['task' => $task]) ?>
```

---

## Getting Started Today

1. **Open** `app/Controller/TaskViewController.php`
2. **Read** the `show()` method
3. **Trace** where data comes from (models)
4. **Find** the template it renders
5. **Modify** something small (add a debug message)
6. **Test** in browser

**The best way to learn is by doing!**

