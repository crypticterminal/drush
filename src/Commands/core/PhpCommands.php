<?php
namespace Drush\Commands\core;

use Drush\Commands\DrushCommands;

class PhpCommands extends DrushCommands
{

    /**
     * Evaluate arbitrary php code after bootstrapping Drupal (if available).
     *
     * @command php-eval
     * @param $code PHP code
     * @usage drush php-eval 'variable_set("hello", "world");'
     *   Sets the hello variable using Drupal API.'
     * @usage drush php-eval '$node = node_load(1); print $node->title;'
     *   Loads node with nid 1 and then prints its title.
     * @usage drush php-eval "file_unmanaged_copy(\'$HOME/Pictures/image.jpg\', \'public://image.jpg\');"
     *   Copies a file whose path is determined by an environment\'s variable. Note the use of double quotes so the variable $HOME gets replaced by its value.
     * @usage drush php-eval "node_access_rebuild();"
     *   Rebuild node access permissions.
     * @aliases eval,ev
     * @bootstrap DRUSH_BOOTSTRAP_MAX
     */
    public function evaluate($code, $options = ['format' => 'var_export'])
    {
        return eval($code . ';');
    }

    /**
     * Run php a script after a full Drupal bootstrap.
     *
     * A useful alternative to eval command when your php is lengthy or you
     * can't be bothered to figure out bash quoting. If you plan to share a
     * script with others, consider making a full Drush command instead, since
     * that's more self-documenting.  Drush provides commandline options to the
     * script via a variable called $extra.
     *
     * @command php-script
     * @option script-path Additional paths to search for scripts, separated by : (Unix-based systems) or ; (Windows).
     * @usage drush php-script example --script-path=/path/to/scripts:/another/path
     *   Run a script named example.php from specified paths
     * @usage drush php-script
     *   List all available scripts.
     * @usage drush php-script foo --apple --cider
     *  Run foo script with argument 'apple' and option 'cider'
     * @aliases scr
     * @bootstrap DRUSH_BOOTSTRAP_MAX
     * @topics docs-examplescript,docs-scripts
     */
    public function script(array $extra, $options = ['format' => 'var_export', 'script-path' => ''])
    {
        $found = false;
        $script = array_shift($extra);

        if ($script == '-') {
            return eval(stream_get_contents(STDIN));
        } elseif (file_exists($script)) {
            $found = $script;
        } else {
            // Array of paths to search for scripts
            $searchpath['cwd'] = drush_cwd();

            // Additional script paths, specified by 'script-path' option
            if ($script_path = $options['script-path']) {
                foreach (explode(PATH_SEPARATOR, $script_path) as $path) {
                    $searchpath[] = $path;
                }
            }
            $this->logger()->debug(dt('Searching for scripts in ') . implode(',', $searchpath));

            if (empty($script)) {
                // List all available scripts.
                $all = array();
                foreach ($searchpath as $key => $path) {
                    $recurse = !(($key == 'cwd') || ($path == '/'));
                    $all = array_merge($all, array_keys(drush_scan_directory($path, '/\.php$/', array('.', '..', 'CVS'), null, $recurse)));
                }
                return implode("\n", $all);
            } else {
                // Execute the specified script.
                foreach ($searchpath as $path) {
                    $script_filename = $path . '/' . $script;
                    if (file_exists($script_filename . '.php')) {
                        $script_filename .= '.php';
                    }
                    if (file_exists($script_filename)) {
                        $found = $script_filename;
                        break;
                    }
                    $all[] = $script_filename;
                }
                if (!$found) {
                    throw new \Exception(dt('Unable to find any of the following: @files', array('@files' => implode(', ', $all))));
                }
            }
        }

        if ($found) {
            // Set the DRUSH_SHIFT_SKIP to two; this will cause
            // drush_shift to skip the next two arguments the next
            // time it is called.  This allows scripts to get all
            // arguments, including the 'php-script' and script
            // pathname, via drush_get_arguments(), or it can process
            // just the arguments that are relevant using drush_shift().
            drush_set_context('DRUSH_SHIFT_SKIP', 2);
            if ($this->evalShebangScript($found) === false) {
                $return = include($found);
                // 1 just means success so don't return it.
                // http://us3.php.net/manual/en/function.include.php#example-120
                if ($return !== 1) {
                    return $return;
                }
            }
        }
    }

    /**
     * Evaluate a script that begins with #!drush php-script
     */
    public function evalShebangScript($script_filename)
    {
        $found = false;
        $fp = fopen($script_filename, "r");
        if ($fp !== false) {
            $line = fgets($fp);
            if (_drush_is_drush_shebang_line($line)) {
                $first_script_line = '';
                while ($line = fgets($fp)) {
                    $line = trim($line);
                    if ($line == '<?php') {
                        $found = true;
                        break;
                    } elseif (!empty($line)) {
                        $first_script_line = $line . "\n";
                        break;
                    }
                }
                $script = stream_get_contents($fp);
                // Pop off the first two arguments, the
                // command (php-script) and the path to
                // the script to execute, as a service
                // to the script.
                eval($first_script_line . $script);
                $found = true;
            }
            fclose($fp);
        }
        return $found;
    }

    /**
     * Command argument complete callback.
     *
     * @return array
     *   Strong glob of files to complete on.
     */
    public static function complete()
    {
        return array(
        'files' => array(
        'directories' => array(
          'pattern' => '*',
          'flags' => GLOB_ONLYDIR,
        ),
        'script' => array(
          'pattern' => '*.php',
        ),
        ),
        );
    }
}
