<?php

/**
 * This file is part of the Tenantable.
 *
 * (c) Nuel Young Chukwunalu <nuelmega@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

/**
 * Messages for the package's tenant-scoped validation rules.
 *
 * CodeIgniter searches every namespace for Language/{locale}/Validation.php
 * and merges what it finds, so these keys join the framework's own without
 * replacing them.
 */
return [
    'is_unique_for_tenant'     => 'The {field} field must contain a unique value.',
    'is_not_unique_for_tenant' => 'The {field} field must contain a previously existing value.',
];
