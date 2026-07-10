<?php

if (! function_exists('current_user_id')) {
    function current_user_id(): ?string
    {
        return auth()->user()?->uuid ?? config('app.user_id');
    }
}
