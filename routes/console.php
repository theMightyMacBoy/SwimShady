<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('swimshady:prune')->daily();
