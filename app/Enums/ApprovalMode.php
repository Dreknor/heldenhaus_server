<?php

namespace App\Enums;

enum ApprovalMode: string
{
    case None = 'none';
    case Required = 'required';
    case Photo = 'photo';
}
