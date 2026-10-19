<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A seeded demo user: an operator (with a tier) or a supervisor (without). There are no passwords (DD6).
 */
#[Fillable(['name', 'role', 'tier'])]
class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
}
