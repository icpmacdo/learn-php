<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The one framework touchpoint at the root of src/ — excluded from deptrac
 * analysis (see deptrac.yaml). Everything else in src/ lives inside a
 * context's Domain / Application / Infrastructure layer.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
