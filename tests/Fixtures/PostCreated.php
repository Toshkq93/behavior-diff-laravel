<?php

namespace Toshkq93\BehaviorDiffLaravel\Tests\Fixtures;

class PostCreated
{
    public $post;

    public function __construct(Post $post)
    {
        $this->post = $post;
    }
}
