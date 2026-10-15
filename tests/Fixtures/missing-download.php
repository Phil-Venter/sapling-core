<?php

use Sapling\Http\Response;

return fn() => Response::file('/no/such/report.pdf');
