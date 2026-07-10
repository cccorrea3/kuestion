<?php

if (! function_exists('current_user_id')) {
    function current_user_id(): int|string|null
    {
        // ponytail: auth()->id() is bigint, questions.user_id is uuid.
        // Resolved in M12 when user uuid column is added.
        return auth()->id() ?? config('app.user_id');
    }
}
