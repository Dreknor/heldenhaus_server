<?php

namespace App\Domain\Gameplay;

use RuntimeException;

/** Fachlicher Fehler mit kindgerechter Meldung (wird als 422 ausgeliefert). */
class GameException extends RuntimeException {}
