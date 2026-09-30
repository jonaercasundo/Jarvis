<?php

namespace App\Services;

use Illuminate\Http\Request;

class WorkflowOwner
{
    /** Use a stable account identity when signed in, otherwise isolate by web session. */
    public function key(Request $request): string
    {
        if ($userId = $request->user()?->getAuthIdentifier()) {
            return 'user:'.$userId;
        }

        return 'session:'.$request->session()->getId();
    }
}
