<?php

namespace IVT\System;

/**
 * Some client code would do special things in the case that fopen() didn't
 * work. In order to migrate that code to use System, System has to throw a
 * specific kind of Exception so that it can be caught and the old behaviour
 * maintained.
 */
class FOpenFailed extends Exception {
}

