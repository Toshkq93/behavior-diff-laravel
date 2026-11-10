<?php

namespace Toshkq93\BehaviorDiffLaravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Toshkq93\BehaviorDiff\Cases\CaseFile;
use Toshkq93\BehaviorDiff\Discovery\Param;
use Toshkq93\BehaviorDiff\Result\ResultFile;
use Toshkq93\BehaviorDiffLaravel\BehaviorDiffServiceProvider;
use Toshkq93\BehaviorDiffLaravel\Discovery\RouteDiscovery;
use Toshkq93\BehaviorDiffLaravel\Tests\Fixtures\Post;
use Toshkq93\BehaviorDiffLaravel\Tests\Fixtures\PostController;
use Toshkq93\BehaviorDiffLaravel\Tests\Fixtures\User;

class AdapterTest extends TestCase
{
    /** @var string */
    private $work;

    protected function getPackageProviders($app)
    {
        return [BehaviorDiffServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $this->work = sys_get_temp_dir().'/bd-laravel-'.mt_rand();

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('behavior-diff.cases', $this->work.'/cases.json');
        $app['config']->set('behavior-diff.app_namespace', 'Toshkq93\\BehaviorDiffLaravel\\Tests\\Fixtures\\');
    }

    protected function defineRoutes($router)
    {
        Route::middleware('web')->group(function () {
            Route::get('login', function () {
                return 'login';
            })->name('login');
            Route::get('posts', [PostController::class, 'index']);
            Route::get('posts/{post}', [PostController::class, 'show']);
            Route::post('posts', [PostController::class, 'store']);
            Route::get('legacy', [PostController::class, 'legacy']);
            Route::get('admin/stats', [PostController::class, 'stats'])->middleware('auth');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('title', 100);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        User::create(['name' => 'Anna', 'email' => 'anna@example.com', 'password' => 'x']);
        User::create(['name' => 'Boris', 'email' => 'boris@example.com', 'password' => 'x']);
        Post::create(['user_id' => 1, 'title' => 'First']);
        Post::create(['user_id' => 2, 'title' => 'Second']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->work.'/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->work);

        parent::tearDown();
    }

    public function test_discovers_routes_rules_bindings_and_users(): void
    {
        $endpoints = [];

        foreach ((new RouteDiscovery($this->app, config('behavior-diff')))->discover() as $endpoint) {
            $endpoints[$endpoint->name()] = $endpoint;
        }

        $this->assertArrayHasKey('GET /posts/{post}', $endpoints);
        $this->assertSame([1, 2], $endpoints['GET /posts/{post}']->params['path:post']->values);

        $store = $endpoints['POST /posts']->params;
        $this->assertSame(Param::TYPE_STRING, $store['body:title']->type);
        $this->assertSame(100, $store['body:title']->maxLength);
        $this->assertTrue($store['body:title']->required);
        $this->assertSame(Param::TYPE_INT, $store['body:user_id']->type);
        $this->assertSame([1, 2], $store['body:user_id']->values);
        $this->assertSame(['draft', 'published'], $store['body:status']->values);

        $this->assertArrayHasKey('query:q', $endpoints['GET /posts']->params);
        $this->assertArrayHasKey('query:id', $endpoints['GET /legacy']->params);

        $this->assertSame([null, 1], $endpoints['GET /admin/stats']->users);
        $this->assertSame([null], $endpoints['GET /posts']->users);
    }

    public function test_runs_are_repeatable_and_leave_no_data_behind(): void
    {
        $this->assertSame(0, Artisan::call('behavior-diff:scan'));
        $this->assertSame(0, Artisan::call('behavior-diff:run', ['--out' => $this->work.'/a.jsonl']));
        $this->assertSame(0, Artisan::call('behavior-diff:run', ['--out' => $this->work.'/b.jsonl']));

        // Every request was rolled back.
        $this->assertSame(2, Post::count());

        $code = Artisan::call('behavior-diff:diff', [
            'old' => $this->work.'/a.jsonl',
            'new' => $this->work.'/b.jsonl',
            '--html' => $this->work.'/report.html',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('No differences found.', $output);

        $results = ResultFile::read($this->work.'/a.jsonl')['results'];
        $cases = [];

        foreach (CaseFile::read($this->work.'/cases.json') as $case) {
            $cases[$case->title().' '.$case->label] = $results[$case->id];
        }

        $store = $cases['POST /posts base'];
        $this->assertSame(201, $store->status);
        $this->assertStringContainsString('"title":"test"', $store->body);
        $this->assertSame(['Toshkq93\BehaviorDiffLaravel\Tests\Fixtures\WelcomeMail to editor@example.com'], $store->effects['mail']);
        $this->assertSame(['Toshkq93\BehaviorDiffLaravel\Tests\Fixtures\PostCreated'], $store->effects['event']);
        $this->assertStringStartsWith('insert into "posts"', $store->effects['db'][0]);
        $this->assertStringContainsString('2024-01-15 12:00:00', $store->effects['db'][0]);

        // HTML request: a failed validation redirects back.
        $this->assertSame(302, $cases['POST /posts title=missing']->status);

        $this->assertSame(302, $cases['GET /admin/stats base']->status);
        $this->assertSame(200, $cases['GET /admin/stats (as 1) base']->status);
        $this->assertSame('{"user":1,"posts":2}', $cases['GET /admin/stats (as 1) base']->body);
    }

    public function test_reports_behaviour_changes(): void
    {
        Artisan::call('behavior-diff:scan');
        Artisan::call('behavior-diff:run', ['--out' => $this->work.'/old.jsonl', '--only' => 'GET /legacy']);

        config(['demo.v2' => true]);
        Artisan::call('behavior-diff:run', ['--out' => $this->work.'/new.jsonl', '--only' => 'GET /legacy']);

        $code = Artisan::call('behavior-diff:diff', ['old' => $this->work.'/old.jsonl', 'new' => $this->work.'/new.jsonl']);
        $output = Artisan::output();

        $this->assertSame(1, $code, $output);
        $this->assertStringContainsString('GET /legacy?id=abc', $output);
        $this->assertStringContainsString('- guest', $output);
        $this->assertStringContainsString('+ user abc', $output);
    }
}
