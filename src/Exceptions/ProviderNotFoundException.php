<?php

declare(strict_types=1);

namespace Nvade\Numerosis\Exceptions;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProviderNotFoundException extends NotFoundHttpException {}
