<?php

namespace Toshkq93\BehaviorDiffLaravel\Tests\Fixtures;

use Illuminate\Mail\Mailable;

class WelcomeMail extends Mailable
{
    public function build()
    {
        return $this->html('Welcome');
    }
}
