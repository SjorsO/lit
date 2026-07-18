<?php

require __DIR__.'/../test-helpers.php';

// Running a command in an empty directory
[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('This is not a Lit directory', $output);

// A directory with a URL file, but no storage directory
mkdir('missing-storage');
file_put_contents('missing-storage/git-repository-url', "https://github.com/test/repo.git\n");

chdir('missing-storage');

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('This looks like a Lit directory, but the storage directory does not exist', $output);
