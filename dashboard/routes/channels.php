<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('readings', fn () => true);
