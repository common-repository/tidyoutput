<?php

namespace TidyOutput;

class HookTest extends \WP_UnitTestCase {

    protected $tidy = null;

    public function setUp() {
        parent::setUp();

        // Set options
        $this->tidy = TidyOutput::get_instance();
        $this->tidy->set_option( TidyOutput::TIDY_METHOD, 'tidy' );
        $this->tidy->set_option( TidyOutput::FULL_PAGE, false );
        $this->tidy->set_option( TidyOutput::CLEANUP, true );
        $this->tidy->set_option( TidyOutput::FORMAT, true );
        $this->tidy->set_option( TidyOutput::EXTRANEOUS_INDENT_CONTENT, 0 );
    }

    public function test_content_filter() {
        $content = trim(
            apply_filters( 'the_content',
                '<p><span>test</span><span></span></p>' ) );
        $this->assertSame( '<p><span>test</span></p>', $content );

        // the_content filter is redundant if we're processing the full page.
        // Set that option and try it out.
        $this->tidy->set_option( TidyOutput::FULL_PAGE, true );
        $content = trim(
            apply_filters( 'the_content',
                '<p><span>test</span><span></span></p>' ) );
        $this->assertSame( '<p><span>test</span><span></span></p>', $content );
    }

    public function test_comment_filter() {
        $content = trim(
            apply_filters( 'pre_comment_content',
                '<a>test</a><a>' ) );
        $this->assertSame( '<a>test</a>', $content );

        // pre_comment_content filter is redundant if we're processing the full
        // page. Set that option and try it out.
        $this->tidy->set_option( TidyOutput::FULL_PAGE, true );
        $content = trim(
            apply_filters( 'pre_comment_content',
                '<a>test</a><a>' ) );
        $this->assertSame( '<a>test</a><a>', $content );
    }

    public function test_page_filter() {
        $this->tidy->set_option( TidyOutput::FULL_PAGE, true );
        $filename = apply_filters( 'template_include', __FILE__ );

        $this->assertSame( dirname( dirname( __FILE__ ) ) . '/template.php',
            $filename );

        $this->assertSame( __FILE__,
            TidyOutput::get_instance()->get_template_filename() );
    }
}
