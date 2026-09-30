<?php

namespace App\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Concerns\RemembersConversations;

class JarvisAgent extends AnonymousAgent
{
    use RemembersConversations;
}
