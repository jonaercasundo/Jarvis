<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class WorkflowOwner
{
    /** Use a stable account identity when signed in, otherwise isolate by web session. */
    public function key(Request $request): string
    {
        if ($userId = $request->user()?->getAuthIdentifier()) {
            return 'user:'.$userId;
        }

        $ownerId = $request->cookie('jarvis_workflow_owner');

        if (! is_string($ownerId) || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $ownerId)) {
            $ownerId = $request->session()->getId();
            Cookie::queue(Cookie::forever('jarvis_workflow_owner', $ownerId));
        }

        return 'session:'.$ownerId;
    }
}
