<?php

namespace TidyOutput;

class TidyOutput {

    /**
     * The name of our plugin
     */
    const NAME = 'tidyoutput';

    /**
     * Config key for the tidy method
     */
    const TIDY_METHOD = 'tidy_method';

    /**
     * Config key for cleaning up the whole page or not
     */
    const FULL_PAGE = 'full_page';

    /**
     * Config key for cleanup enabled
     */
    const CLEANUP = 'cleanup';

    /**
     * Config key for formatting enabled
     */
    const FORMAT = 'format';

    /**
     * Config key for extra indent levels. This is the legacy version (was a
     * single setting for content)
     */
    const EXTRANEOUS_INDENT_LEGACY = 'indent';

    /**
     * Config key for setting extra indent levels (post content)
     */
    const EXTRANEOUS_INDENT_CONTENT = 'indent_content';

    /**
     * Config key for setting extra indent levels (post comments)
     */
    const EXTRANEOUS_INDENT_COMMENT = 'indent_comment';

    /**
     * The path to our views. This is relative to our plugin root.
     */
    const PATH_VIEWS = '/views/';

    /**
     * The path to our translations. Note that this is relative to our plugin
     * root.
     */
    const PATH_LANGUAGES = '/languages/';

    /**
     * Maximum number of extra indents
     */
    const MAX_EXTRANEOUS_INDENT = 20;

    /**
     * Number of minutes to tell the browser to cache JavaScript for
     */
    const JAVASCRIPT_EXPIRES_MINUTES = 60;

    /**
     * Constant indicating content data type
     */
    const TYPE_CONTENT = 0;

    /**
     * Constant indicating comment data type
     */
    const TYPE_COMMENT = 1;

    /**
     * Constant indicating a full page
     */
    const TYPE_FULL_PAGE = 2;

    /**
     * @var TidyOutput|null The current instance object
     */
    protected static $instance = null;

    /**
     * @var bool Indicates whether we are currently capturing output or not
     */
    protected $capturing = false;

    /**
     * @var string The template filename to load
     */
    protected $template_filename = '';

    /**
     * @var array The default settings. Note that these are set in the
     * constructor
     */
    protected $defaults = array();

    /**
     * @var array The options for this plugin
     */
    protected $options = array();

    /**
     * @var array An array of classes for handling things
     */
    protected $handlers = array();

    /**
     * Installs WP hooks and loads options
     *
     * @param bool $attach Should we attach to WordPress?
     */
    protected function __construct( $attach ) {
        $methods = $this->get_available_methods();
        $first_method = reset( $methods );

        // Set defaults. TIDY_METHOD must be before CLEANUP and FORMAT for
        // validation to function properly.
        $this->defaults = $this->options = array(
            static::TIDY_METHOD => $first_method['name'],
            static::FULL_PAGE => false,
            static::CLEANUP => true,
            static::FORMAT => false,
            static::EXTRANEOUS_INDENT_COMMENT => 0,
            static::EXTRANEOUS_INDENT_CONTENT => 0,
        );

        // Default header handler
        $this->register_handler('http_header', 'HttpHeaderOutputHandler');

        if ( ! $attach ) {
            // There is nothing else to do if we're not attaching to WordPress.
            return;
        }

        // Load options
        $this->options = get_option( static::NAME );

        // Make sure the data is sane. This will also prevent a fatal error if,
        // for example, tidy is uninstalled but is still configured for use.
        $this->options = $this->clean_options( $this->options );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( &$this, 'add_page' ) );
            add_action( 'admin_init', array( &$this, 'admin_init' ) );
        } else {
            add_action( 'init', array( &$this, 'init' ) );
        }
    }

    /**
     * Registers a handler identified by $key
     *
     * @param string $key The key that identifies this handler
     * @param string $class The name of the class to register
     * @return bool True on success, false on failure
     */
    public function register_handler( $key, $class ) {

        $classes = array();

        // Priority is the class exactly as passed in
        $classes[] = $class;

        if ( $class[0] != '\\' ) {
            // Try the root namespace if it isn't an absolute class path
            $classes[] = '\\' . $class;

            if ( strpos( $class, '\\' )  === false ) {
                // Try our namespace if there is no namespace in the class path
                $classes[] = '\\' . __NAMESPACE__ . '\\' . $class;
            }
        }

        // Terminator
        $classes[] = null;

        foreach ( $classes as $class ) {
            if ( class_exists( $class ) ) {
                $this->handlers[ $key ] = $class;
                break;
            }
        }

        if ( $class === null ) {
            return false;
        }

        return true;
    }

    /**
     * Returns a reference to the handler identified by $key
     *
     * @param string $key
     *
     * @return mixed
     */
    public function &get_handler( $key ) {
        if ( ! isset( $this->handlers[ $key ] ) ) {
            $this->handlers[ $key ] = null;
        }

        if ( $this->handlers[ $key ] !== null
                && ! is_object( $this->handlers[ $key ] ) ) {
            // Don't initialize until we need it. Note that register_handler
            // will not set a handler that is invalid, so there is no need
            // to validate here.
            $this->handlers[ $key ] = new $this->handlers[ $key ];
        }

        return $this->handlers[ $key ];
    }

    /**
     * Sets up an instance or returns the previously created one by reference
     *
     * @return TidyOutput
     */
    public static function &attach() {
        return static::get_instance( true );
    }

    /**
     * Returns an instance of TidyOutput by reference. If $attach is true, we'll
     * attach all the WordPress hooks we need. The last instance created with
     * $attach = true is the one that gets priority.
     *
     * @param bool $attach Should we attach to WordPress?
     * @param bool $create Force creation of a new instance?
     *
     * @return TidyOutput
     */
    public static function &get_instance( $attach = false, $create = false ) {
        if ( static::$instance === null || $attach ) {
            // Create an instance
            static::$instance = new TidyOutput( $attach );
        } else if ( $create ) {
            // Return a new instance but retain the old one
            $new_instance = new TidyOutput( $attach );

            return $new_instance;
        }

        return static::$instance;
    }

    /**
     * Adds filters related to the admin area
     */
    public function admin_init() {
        // Translation
        load_plugin_textdomain( 'tidyoutput', false,
            dirname( plugin_basename( dirname( __FILE__ ) ) )
            . static::PATH_LANGUAGES );

        // Settings fields
        register_setting( static::NAME, static::NAME,
            array( &$this, 'clean_options' ) );
        add_settings_section( 'tidyoutput', null, null, static::NAME );
        add_settings_field( static::NAME . '_' . static::TIDY_METHOD,
            __( 'Method', 'tidyoutput' ), array( &$this, 'field_method' ),
            static::NAME, 'tidyoutput' );
        add_settings_field( static::NAME . '_' . static::FULL_PAGE,
            __( 'Cleanup full page', 'tidyoutput' ),
            array( &$this, 'field_full_page' ), static::NAME, 'tidyoutput' );
        add_settings_field( static::NAME . '_' . static::CLEANUP,
            __( 'Cleanup bad HTML', 'tidyoutput' ),
            array( &$this, 'field_cleanup' ), static::NAME, 'tidyoutput' );
        add_settings_field( static::NAME . '_' . static::FORMAT,
            __( 'Format messy HTML', 'tidyoutput' ),
            array( &$this, 'field_format' ), static::NAME, 'tidyoutput' );
        add_settings_field( static::NAME . '_' . static::EXTRANEOUS_INDENT_CONTENT,
            __( 'Extra content indentation', 'tidyoutput' ),
            array( &$this, 'field_indent_content' ), static::NAME, 'tidyoutput' );
        add_settings_field( static::NAME . '_' . static::EXTRANEOUS_INDENT_COMMENT,
            __( 'Extra comment indentation', 'tidyoutput' ),
            array( &$this, 'field_indent_comment' ), static::NAME, 'tidyoutput' );

        // Scripts
        add_action( 'admin_enqueue_scripts',
            array( &$this, 'admin_enqueue_scripts' ) );
    }

    /**
     * @param string $hook The page we're on
     */
    public function admin_enqueue_scripts( $hook ) {
        if ( $hook == 'settings_page_' . static::NAME ) {
            $url = plugin_dir_url( __DIR__ )
                . ltrim( static::PATH_VIEWS, '/' ) . 'settings_form.js.php';
            wp_enqueue_script( 'tidyoutput', $url, array( 'jquery' ) );
        }
    }

    /**
     * Adds applicable filters and sets up translation
     */
    public function init() {
        add_filter( 'template_include', array( &$this, 'swap_template' ), 99 );
        add_filter( 'the_content', array( &$this, 'clean_content' ), 20 );
        add_filter( 'pre_comment_content', array( &$this, 'clean_comment' ), 20 );
    }

    /**
     * Uninstall the plugin, deleting its settings
     */
    public function uninstall() {
        delete_option( static::NAME );
        delete_site_option( static::NAME );
    }

    /**
     * Validates our options and cleans them up. Any that are invalid or missing
     * will be replaced with defaults.
     *
     * @param array $input The input options
     *
     * @return array
     */
    public function clean_options( $input ) {
        // Start out with the defaults and overwrite the rest
        $cleaned = $this->defaults;

        if ( isset( $input[ static::EXTRANEOUS_INDENT_LEGACY ] ) ) {
            // Legacy replaces CONTENT
            $input [ static::EXTRANEOUS_INDENT_CONTENT ] = $input[ static::EXTRANEOUS_INDENT_LEGACY ];
        }

        // Set defaults for any missing options
        foreach ( array_keys( $this->defaults ) as $key ) {
            if ( ! isset ( $input[ $key ] ) ) {
                // Only look at options that are available
                continue;
            }

            $clean = null;

            switch ( $key ) {
                case static::TIDY_METHOD:
                    if ( $this->supports_method( $input[ $key ] ) ) {
                        $clean = $input[ $key ];
                    }
                    break;
                case static::CLEANUP:
                case static::FORMAT:
                case static::FULL_PAGE:
                    // If the requested action is not supported, disable by
                    // default.
                    if ( ! $this->supports_action( $key,
                            $cleaned[ static::TIDY_METHOD ] ) ) {
                        $input[ $key ] = false;
                    }
                    $clean = filter_var( $input[ $key ],
                        FILTER_VALIDATE_BOOLEAN );
                    break;
                case static::EXTRANEOUS_INDENT_COMMENT:
                case static::EXTRANEOUS_INDENT_CONTENT:
                    $clean = filter_var( $input[ $key ], FILTER_VALIDATE_INT );
                    if ( $clean < 0
                            || $clean > static::MAX_EXTRANEOUS_INDENT ) {
                        $clean = null;
                    }
                    break;
            }

            if ( $clean !== null ) {
                $cleaned[ $key ] = $clean;
            }
        }

        return $cleaned;
    }

    /**
     * Gets the option identified by key. Returns null on failure
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get_option( $key ) {
        if ( ! isset ( $this->options[ $key ] ) ) {
            return null;
        }

        return $this->options[ $key ];
    }

    /**
     * Gets the default option identified by key. Returns null on failure
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get_default_option( $key ) {
        if ( ! isset ( $this->defaults[ $key ] ) ) {
            return null;
        }

        return $this->defaults[ $key ];
    }

    /**
     * Sets the option identified by key to value. Returns true on success or
     * false on failure.
     *
     * @param string $key
     * @param string $value
     *
     * @return bool
     */
    public function set_option( $key, $value ) {
        if ( ! $this->is_legacy_option( $key ) &&
                ! isset ( $this->defaults[ $key ] ) ) {
            return false;
        }

        $options = $this->options;
        $options[ $key ] = $value;

        $this->options = $this->clean_options( $options );

        // Save options
        return update_option( static::NAME, $this->options );
    }

    /**
     * Returns true if this is a legacy option. Else false.
     *
     * @param string $key The options key we're looking at
     *
     * @return bool
     */
    public function is_legacy_option( $key ) {
        switch ( $key ) {
            case static::EXTRANEOUS_INDENT_LEGACY:
                return true;
        }

        return false;
    }

    /**
     * Displays the options page for the plugin
     */
    public function options_page() {
        $methods = $this->get_available_methods();
        require dirname( __DIR__ ) . static::PATH_VIEWS . 'settings_form.php';
    }

    /**
     * Renders the Method field
     */
    public function field_method() {
        $values = $this->get_available_methods();
        $options = $this->options;
        $field = static::TIDY_METHOD;
        $description = __( 'Choose the method you would like to use to'
            . ' manipulate HTML.', 'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/select.php';
    }

    /**
     * Renders the Cleanup Full Page field
     */
    public function field_full_page() {
        $options = $this->options;
        $field = static::FULL_PAGE;
        $description = __( 'Select True to cleanup the full page, or False'
            . ' to only cleanup post content.', 'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/boolean.php';
    }

    /**
     * Renders the Enable Cleanup field
     */
    public function field_cleanup() {
        $options = $this->options;
        $field = static::CLEANUP;
        $description = __( 'Select True to fix bad HTML, or False to disable.',
            'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/boolean.php';
    }

    /**
     * Renders the Enable HTML Formatting field
     */
    public function field_format() {
        $options = $this->options;
        $field = static::FORMAT;
        $description = __( 'Select True to format messy HTML. Note that this'
            . ' can be useful when debugging but should usually be disabled'
            . ' on a production site as it adds bloat.', 'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/boolean.php';
    }

    /**
     * Renders the Extraneous Indent field (post content)
     */
    public function field_indent_content() {
        $options = $this->options;
        $field = static::EXTRANEOUS_INDENT_CONTENT;
        $range = range( 0, static::MAX_EXTRANEOUS_INDENT );
        $description = __( 'If not formatting the entire page, it can make the'
            . ' code cleaner by adding some extra indentation to post content.'
            . ' Select the level of indentation to add (1 level equals 4'
            . ' spaces) or 0 to disable this feature.', 'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/range.php';
    }

    /**
     * Renders the Extraneous Indent field (post comments)
     */
    public function field_indent_comment() {
        $options = $this->options;
        $field = static::EXTRANEOUS_INDENT_COMMENT;
        $range = range( 0, static::MAX_EXTRANEOUS_INDENT );
        $description = __( 'If not formatting the entire page, it can make the'
            . ' code cleaner by adding some extra indentation to post comments.'
            . ' Select the level of indentation to add (1 level equals 4'
            . ' spaces) or 0 to disable this feature.', 'tidyoutput' );

        require dirname( __DIR__ ) . static::PATH_VIEWS . 'fields/range.php';
    }

    /**
     * Adds a configuration page for our plugin
     */
    public function add_page() {
        add_options_page( __( 'Tidy Output Settings', 'tidyoutput' ),
            __( 'Tidy Output', 'tidyoutput' ),
            'manage_options', static::NAME, array(
                &$this,
                'options_page'
            ) );
    }

    /**
     * Formats and cleans the content using the Tidy library
     *
     * @param string $content The content to clean
     * @param bool $full_html Indicates whether this is a full html page or not
     *
     * @return string
     */
    public function clean_tidy( $content, $full_html = false ) {
        $tidy = new \tidy();

        $config = array(
            'show-body-only' => ! $full_html,
        );
        $indent_config = array(
            'indent' => '2',
            'indent-spaces' => 4
        );

        $html5 = $full_html && stripos( $content, '<!doctype html>') === 0;

        if ( $this->get_option( static::CLEANUP )
                && $this->get_option( static::FORMAT ) ) {
            // Cleanup and format
            $config = array_merge( $config, $indent_config );
            if ( ! $tidy->parseString( $content, $config, 'UTF8' )
                    || ! $tidy->cleanRepair() ) {
                return $content;
            }

            $content = (string) $tidy;
        } else if ( $this->get_option( static::CLEANUP ) ) {
            // Cleanup only
            $content = $tidy->repairString( $content, $config, 'UTF8' );
        } else if ( $this->get_option( static::FORMAT ) ) {
            // Format only
            $config = array_merge( $config, $indent_config );
            if ( ! $tidy->parseString( $content, $config, 'UTF8' ) ) {
                return $content;
            }

            $content = (string) $tidy;
        } else {
            // Unknown options are in use
            return $content;
        }

        if ( $html5 && $full_html ) {
            // Strip doctype
            $content = preg_replace('/\<\!doctype.+\>/i', '', $content, 1);

            // Add correct doctype
            $content = '<!doctype html>' . PHP_EOL . $content;
        }

        return $content;
    }

    /**
     * Formats and cleans the content using the DOMDocument class
     *
     * @param string $content The content to clean
     * @param bool $full_html Indicates whether this is a full html page or not
     *
     * @return string
     */
    public function clean_domdocument( $content, $full_html = false ) {
        if ( ! $this->get_option( static::CLEANUP ) ) {
            // DOMDocument only supports cleanup. If that isn't enabled, abort.
            return $content;
        }

        libxml_use_internal_errors( true );
        libxml_clear_errors();

        $input = new \DOMDocument();
        $input->recover = true;

        $uid = uniqid();

        if ( ! $full_html ) {
            $content = '<html><body id="' . $uid . '">' . $content . '</body>';
        }

        $input->loadHTML( $content );

        if ( $full_html ) {
            $dom = $input->getElementsByTagName( 'html' );
            if ( $dom->length == 0 ) {
                $dom = &$input;
            } else {
                $dom = $dom->item( 0 );
            }
        } else {
            $dom = $input->getElementById( $uid );
        }

        $output = new \DOMDocument();
        $output->encoding = 'UTF-8';

        $has_xpath = class_exists( '\\DOMXpath' );

        if ( $full_html && ( $dom_doctype = $input->doctype ) != '' ) {
            $doctype = '<!DOCTYPE ' . $dom_doctype->name;

            if ( trim( $dom_doctype->publicId ) != ''
                    && trim( $dom_doctype->systemId ) != '' ) {

                $doctype .= ' PUBLIC'
                    . ' "' . htmlentities( trim( $dom_doctype->publicId ) ) . '"'
                    . ' "' . htmlentities( trim( $dom_doctype->systemId ) ) . '"';
            }

            $doctype .= '>';
        } else if ( $full_html ) {
            $doctype = '<!DOCTYPE html>';
        } else {
            $doctype = '';
        }

        $failed = false;
        foreach ( $dom->childNodes as $child ) {
            if ( is_a( $child, '\\DOMDocumentType' ) ) {
                // Skip doctype node
                continue;
            }

            // If we don't have XPath, do the best we can to remove empty nodes.
            // I decided to not make this recursive for performance reasons and
            // since empty tags aren't actually invalid.
            if ( ! $has_xpath && isset( $child->childNodes )
                    && ( $child->childNodes->length === 0
                    && $child->attributes->length === 0 ) ) {
                // Skip empty nodes
                continue;
            }

            $result = $output->importNode( $child, true );
            if ( $result === false ) {
                $failed = true;
                break;
            }
            $output->appendChild( $result );
        }

        if ( ! $failed ) {

            // Use DOMXpath to remove empty nodes if possible
            if ( $has_xpath ) {
                $xpath = new \DOMXPath( $output );

                foreach ( $xpath->query( '//*[not(node() or @*)]' ) as $node ) {
                    $node->parentNode->removeChild( $node );
                }
            }

            return $doctype . $output->saveHTML();
        }

        return $content;
    }

    /**
     * Adds extra indents based on the plugin's settings. This supports \r, \n,
     * \r\n, and \n\r line endings. Note that while RS and 0x9B are technically
     * valid line endings, I chose not to support them as they are unlikely to
     * show up. However, support can be added by simply adding to the $lf array.
     * Please also note that the wpautop filter will convert some newlines to \n
     * so the point is moot if that is enabled.
     *
     * @param string $content
     * @param int $indents
     *
     * @return string
     */
    public function indent( $content, $indents ) {
        $length = strlen( $content );
        $indented = '';
        $indent = str_repeat( ' ', $indents * 4 );
        $lf = array( "\r", "\n" );

        for ( $i = 0; $i < $length; $i ++ ) {

            $newlines = $i === 0;

            // Scan for newlines
            for ( ; $i < $length && in_array( $content[ $i ], $lf ); $i ++ ) {
                $indented .= $content[ $i ];
                $newlines = true;
            }

            if ( $i < $length ) {
                if ( $newlines ) {
                    // Only add indent if there were actually newlines found,
                    // or this is the first character.
                    $indented .= $indent;
                }
                $indented .= $content[ $i ];
            }
        }

        // Return result
        return $indented;
    }

    /**
     * Formats and cleans html data
     *
     * @param string $content The content to clean
     * @param bool $full_html Indicates whether this is a full html page or not
     * @param int $type The type of content we're cleaning (static::TYPE_CONTENT
     * or static::TYPE_COMMENT)
     *
     * @return string
     */
    public function clean( $content, $full_html = false, $type ) {
        // Get the tidy method
        $full_page = $this->get_option( static::FULL_PAGE );

        switch ( $type ) {
            case static::TYPE_CONTENT:
                $indents = $this->get_option( static::EXTRANEOUS_INDENT_CONTENT );
                break;
            case static::TYPE_COMMENT:
                $indents = $this->get_option( static::EXTRANEOUS_INDENT_COMMENT );
                break;
            case static::TYPE_FULL_PAGE:
                $indents = 0;
                break;
            default:
                throw new \InvalidArgumentException('Invalid type specified!');
        }

        if ( ! $full_html && $full_page && ! $indents ) {
            // Don't apply this filter if extraneous indentation is disabled
            // and we're processing the full page
            return $content;
        }

        $method = 'clean_' . $this->get_option( static::TIDY_METHOD );

        if ( method_exists( $this, $method ) && $this->supports_any_action() ) {
            $content = $this->$method( $content, $full_html );
        }

        if ( ! $full_html && ( ! $this->get_option( static::FORMAT )
                || ! $this->capturing )
                && $indents > 0 ) {
            $content = $this->indent( $content, $indents );
        }

        // Return result
        return $content;
    }

    /**
     * Formats and cleans html content
     *
     * @param string $content The content to clean
     * @param bool $full_html Indicates whether this is a full html page or not
     *
     * @return string
     */
    public function clean_content( $content, $full_html = false ) {
        return $this->clean( $content, $full_html, static::TYPE_CONTENT );
    }

    /**
     * Formats and cleans html comments
     *
     * @param string $comment The comment to clean
     * @param bool $full_html Indicates whether this is a full html page or not
     *
     * @return string
     */
    public function clean_comment( $comment, $full_html = false ) {
        return $this->clean( $comment, $full_html, static::TYPE_COMMENT );
    }

    /**
     * Returns our template file and stores the provided one. This is so that we
     * can render a full page.
     *
     * @param string $template
     *
     * @return string
     */
    public function swap_template( $template ) {
        // Get the tidy method
        $full_page = $this->get_option( static::FULL_PAGE );

        if ( ! $full_page || ! $this->supports_any_action() ) {
            // This filter is not applicable.
            return $template;
        }

        $this->template_filename = $template;

        if ( $template == '' ) {

            // We won't be able to do anything with an empty template!
            return $template;
        }

        return dirname( __DIR__ ) . '/template.php';
    }

    /**
     * Returns the template filename we were passed via the template_include
     * filter
     *
     * @return string
     */
    public function get_template_filename() {
        return $this->template_filename;
    }

    /**
     * Begins capturing output. This uses a callback to attempt to detect the
     * buffer getting too large and try to deal with it before it becomes a
     * problem (since our processing of the output is purely to cleanup the
     * source with is pretty superfluous)
     */
    public function begin_output_capture() {
        // Only start if there is actually something we can do with the data
        if ( ! $this->capturing && $this->supports_any_action() ) {
            $this->capturing = true;
            ob_start();
        }
    }

    /**
     * Stops capturing output, cleans it, and outputs the result.
     */
    public function end_output_capture() {
        if ( $this->capturing ) {
            $buffer = ob_get_clean();
            $this->capturing = false;

            // Output result
            echo $this->clean( $buffer, static::TYPE_FULL_PAGE, true );
        }
    }

    /**
     * Sends JavaScript-related headers (content type, cache-control, and
     * expires.)
     *
     * This is for use with dynamic JS that is only dynamic so far that it uses
     * settings from this class.
     *
     * Note that this is safe to use without being attached to WordPress.
     */
    public function send_javascript_headers() {
        // Calculate time in the future
        $datetime = \DateTime::createFromFormat( 'U',
            time() + 60 * static::JAVASCRIPT_EXPIRES_MINUTES,
            new \DateTimeZone( 'GMT' ) );

        $handler = $this->get_handler( 'http_header' );

        if ( $handler === null
                || ! is_a( $handler, __NAMESPACE__
                . '\\HttpHeaderHandlerInterface' ) ) {
            error_log( 'Invalid handler for http_header' );
            return;
        }

        // Send headers
        $handler->add_header( 'Content-Type', 'application/javascript' );
        $handler->add_header( 'Cache-Control', 'max-age='
            . ( 60 * static::JAVASCRIPT_EXPIRES_MINUTES ) );
        $handler->add_header( 'Expires',
            $datetime->format( \DateTime::RFC1123 ) );
    }

    /**
     * Returns all cleanup methods that are available on this system. This is
     * safe to use without being attached to WordPress.
     *
     * @return array
     */
    public function get_available_methods() {
        $methods = array();

        if ( $this->supports_method( 'tidy' ) ) {
            $methods['tidy'] = array(
                'name' => 'tidy',
                'title' => 'Tidy',
                'supports' => array(
                    static::CLEANUP,
                    static::FORMAT,
                    static::FULL_PAGE
                )
            );
        }

        if ( $this->supports_method( 'domdocument' ) ) {
            $methods['domdocument'] = array(
                'name' => 'domdocument',
                'title' => 'DOMDocument',
                'supports' => array( static::CLEANUP, static::FULL_PAGE )
            );
        }

        $methods['disable'] = array(
            'name' => 'disable',
            'title' => 'Disable',
            'supports' => array()
        );

        if ( count( $methods ) > 0 ) {
            reset( $methods );
            $methods[ key( $methods ) ]['title'] .= ' (Recommended)';
        }

        return $methods;
    }

    /**
     * Checks if any of the any actions are supported by the configured
     * method
     *
     * @return bool
     */
    protected function supports_any_action() {
        // Get the current method
        $tidy_method = $this->get_option( static::TIDY_METHOD );

        // Fetch methods
        $methods = $this->get_available_methods();

        // Find the method in question
        $method = $methods[ $tidy_method ];

        return ! empty( $method['supports'] );
    }

    /**
     * Checks if the requested action is supported by the configured method
     *
     * @param string $action
     *
     * @return bool
     */
    protected function supports_action( $action ) {
        // Get the current method
        $tidy_method = $this->get_option( static::TIDY_METHOD );

        // Fetch methods
        $methods = $this->get_available_methods();

        // Find the method in question
        $method = $methods[ $tidy_method ];

        return in_array( $action, $method['supports'] );
    }

    /**
     * Checks if the requested method is supported on this system
     *
     * @param string $tidy_method
     *
     * @return bool
     */
    protected function supports_method( $tidy_method ) {
        switch ( $tidy_method ) {
            case 'tidy':
                return class_exists( '\tidy' );
            case 'domdocument':
                return class_exists( '\DOMDocument' );
            case 'disable':
                return true;
            default:
                return false;
        }
    }
}
