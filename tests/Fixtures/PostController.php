<?php

namespace Toshkq93\BehaviorDiffLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $query = Post::query()->orderBy('id');

        if ($request->input('q') !== null) {
            $query->where('title', 'like', '%'.$request->input('q').'%');
        }

        return ['data' => $query->get(['id', 'title', 'status']), 'now' => now()->toDateTimeString()];
    }

    public function show(Post $post)
    {
        return $post;
    }

    public function store(StorePostRequest $request)
    {
        $post = Post::create($request->validated());
        event(new PostCreated($post));
        Mail::to('editor@example.com')->send(new WelcomeMail());

        return response()->json($post, 201);
    }

    // Simulates a PHP 8 change: "abc" == 0 is true on the "old" version only.
    public function legacy(Request $request)
    {
        $id = $request->query('id', '');
        $old = ! config('demo.v2');
        $isZero = $old ? ($id === '' || ! is_numeric($id) || (int) $id === 0) : ((string) $id === '0');

        return $isZero ? 'guest' : 'user '.$id;
    }

    public function stats(Request $request)
    {
        return ['user' => $request->user()->id, 'posts' => Post::count()];
    }
}
