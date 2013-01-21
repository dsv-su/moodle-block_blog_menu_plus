<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Blog Menu Block page.
 *
 * @package    block
 * @subpackage blog_menu_plus
 * @copyright  2009 Nicolas Connault
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Code modified to make block independent of blog/lib.php by Simon Jarbrant for Stockholm University,
 *         Department of Computer and System Sciences, DSV
 * @copyright &copy; 2013 Stockholm University, Department of Computer and System Sciences, DSV
 * @author Simon Jarbrant <sija8687@student.su.se>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The blog menu block class
 */
class block_blog_menu_plus extends block_base {

    function init() {
        $this->title = get_string('pluginname', 'block_blog_menu_plus');
    }

    function instance_allow_multiple() {
        return true;
    }

    function has_config() {
        return false;
    }

    function applicable_formats() {
        return array('all' => true, 'my' => false, 'tag' => false);
    }

    function instance_allow_config() {
        return true;
    }

    function get_content() {
        global $CFG;

        // detect if blog enabled
        if ($this->content !== NULL) {
            return $this->content;
        }

        if (empty($CFG->enableblogs)) {
            $this->content = new stdClass();
            $this->content->text = '';
            if ($this->page->user_is_editing()) {
                $this->content->text = get_string('blogdisable', 'blog');
            }
            return $this->content;

        } else if ($CFG->bloglevel < BLOG_GLOBAL_LEVEL and (!isloggedin() or isguestuser())) {
            $this->content = new stdClass();
            $this->content->text = '';
            return $this->content;
        }

        // Prep the content
        $this->content = new stdClass();

        $options = $this->get_all_blog_options($this->page);
        if (count($options) == 0) {
            $this->content->text = '';
            return $this->content;
        }

        // Iterate the option types
        $menulist = array();
        foreach ($options as $types) {
            foreach ($types as $link) {
                $menulist[] = html_writer::link($link['link'], $link['string']);
            }
            $menulist[] = '<hr />';
        }
        // Remove the last element (will be an HR)
        array_pop($menulist);
        // Display the content as a list
        $this->content->text = html_writer::alist($menulist, array('class'=>'list'));

        // Prepare the footer for this block
        if (has_capability('moodle/blog:search', context_system::instance())) {
            // Full-text search field
            $form  = html_writer::tag('label', get_string('search', 'admin'), array('for'=>'blogsearchquery', 'class'=>'accesshide'));
            $form .= html_writer::empty_tag('input', array('id'=>'blogsearchquery', 'type'=>'text', 'name'=>'search'));
            $form .= html_writer::empty_tag('input', array('type'=>'submit', 'value'=>get_string('search')));
            $this->content->footer = html_writer::tag('form', html_writer::tag('div', $form), array('class'=>'blogsearchform', 'method'=>'get', 'action'=>new moodle_url('/blog/index.php')));
        } else {
            // No footer to display
            $this->content->footer = '';
        }

        // Return the content object
        return $this->content;
    }

    function blog_is_enabled_for_user() {
        global $CFG;
        return (!empty($CFG->enableblogs) && (isloggedin() || ($CFG->bloglevel == BLOG_GLOBAL_LEVEL)));
    }

    function get_all_blog_options(moodle_page $page, stdClass $userid = null) {
        global $CFG, $DB, $USER;

        $options = array();

        // If blogs are enabled and the user is logged in and not a guest
        if ($this->blog_is_enabled_for_user()) {
            // If the context is the user then assume we want to load for the users context
            if (is_null($userid) && $page->context->contextlevel == CONTEXT_USER) {
                $userid = $page->context->instanceid;
            }
            // Check the userid var
            if (!is_null($userid) && $userid!==$USER->id) {
                // Load the user from the userid... it MUST EXIST throw a wobbly if it doesn't!
                $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);
            } else {
                $user = null;
            }

            if ($CFG->useblogassociations && $page->cm !== null) {
                // Load for the module associated with the page
                $options[CONTEXT_MODULE] = $this->get_blog_options_for_module($page->cm, $user);
            } else if ($CFG->useblogassociations && $page->course->id != SITEID) {
                // Load the options for the course associated with the page
                $options[CONTEXT_COURSE] = $this->get_blog_options_for_course($page->course, $user);
            }

            // Get the options for the user
            if ($user !== null and !isguestuser($user)) {
                // Load for the requested user
                $options[CONTEXT_USER+1] = $this->get_blog_options_for_user($user);
            }
            // Load for the current user
            if (isloggedin() and !isguestuser()) {
                $options[CONTEXT_USER] = $this->get_blog_options_for_user();
            }
        }

        // If blog level is global then display a link to view all site entries
        if (!empty($CFG->enableblogs) && $CFG->bloglevel >= BLOG_GLOBAL_LEVEL && has_capability('moodle/blog:view', context_system::instance())) {
            $options[CONTEXT_SYSTEM] = array('viewsite' => array(
                'string' => get_string('viewsiteentries', 'blog'),
                'link' => new moodle_url('/blog/index.php')
            ));
        }

        // Return the options
        return $options;
    }

    function get_blog_options_for_user(stdClass $user=null) {
        global $CFG, $USER;
        // Cache
        static $useroptions = array();

        $options = array();
        // Blogs must be enabled and the user must be logged in
        if (!($this->blog_is_enabled_for_user())) {
            return $options;
        }

        // Sort out the user var
        if ($user === null || $user->id == $USER->id) {
            $user = $USER;
            $iscurrentuser = true;
        } else {
            $iscurrentuser = false;
        }

        // If we've already generated serve from the cache
        if (array_key_exists($user->id, $useroptions)) {
            return $useroptions[$user->id];
        }

        $sitecontext = context_system::instance();
        $canview = has_capability('moodle/blog:view', $sitecontext);

        if (!$iscurrentuser && $canview && ($CFG->bloglevel >= BLOG_SITE_LEVEL)) {
            // Not the current user, but we can view and its blogs are enabled for SITE or GLOBAL
            $options['userentries'] = array(
                'string' => get_string('viewuserentries', 'blog', fullname($user)),
                'link' => new moodle_url('/blog/index.php', array('userid'=>$user->id))
            );
        } else {
            // It's the current user
            if ($canview) {
                // We can view our own blogs .... BIG surprise
                $options['view'] = array(
                    'string' => get_string('viewallmyentries', 'blog'),
                    'link' => new moodle_url('/blog/index.php', array('userid'=>$USER->id))
                );
            }
            if (has_capability('moodle/blog:create', $sitecontext)) {
                // We can add to our own blog
                $options['add'] = array(
                    'string' => get_string('addnewentry', 'blog'),
                    'link' => new moodle_url('/blog/edit.php', array('action'=>'add'))
                );
            }
        }
        if ($canview && $CFG->enablerssfeeds) {
            $options['rss'] = array(
                'string' => get_string('rssfeed', 'blog'),
                'link' => new moodle_url(rss_get_url($sitecontext->id, $USER->id, 'blog', 'user/'.$user->id))
            );
        }

        // Cache the options
        $useroptions[$user->id] = $options;
        // Return the options
        return $options;
    }

    function get_blog_options_for_course(stdClass $course, stdClass $user=null) {
        global $CFG, $USER;
        // Cache
        static $courseoptions = array();

        $options = array();

        // User must be logged in and blogs must be enabled
        if (!($this->blog_is_enabled_for_user())) {
            return $options;
        }

        // Check that user can associate with the course
        $sitecontext = context_system::instance();
        $coursecontext = context_course::instance($course->id);
        if (!has_capability('moodle/blog:associatecourse', $coursecontext)) {
            return $options;
        }

        // Generate the cache key
        $key = $course->id.':';
        if (!empty($user)) {
            $key .= $user->id;
        } else {
            $key .= $USER->id;
        }

        // Serve from the cache if we've already generated for this course
        if (array_key_exists($key, $courseoptions)) {
            return $courseoptions[$key];
        }

        $canparticipate = (is_enrolled($coursecontext) or is_viewing($coursecontext));

        if (has_capability('moodle/blog:view', $coursecontext)) {
            // We can view!
            if ($CFG->bloglevel >= BLOG_SITE_LEVEL) {
                // If the course isn't using separate groups; view all entries associated with this course
                if ($course->groupmode != SEPARATEGROUPS || has_capability('mod/assign:grade', $coursecontext)) {
                    $options['courseview'] = array(
                        'string' => get_string('viewcourseblogs', 'blog'),
                        'link' => new moodle_url('/blog/index.php', array('courseid' => $course->id))
                    );
                }

                // If the course is using groups (separate or visible) and the user is in a group; view entries by the users group.
                if (($course->groupmode > NOGROUPS) && ($groupid = groups_get_course_group($course))) {
                    $options['courseviewgroup'] = array(
                        'string' => get_string('viewentriesbyuseraboutcourse', 'blog', groups_get_group_name($groupid)),
                        'link' => new moodle_url('/blog/index.php', array('courseid' => $course->id, 'groupid' => $groupid))
                    );
                }

                // View MY entries about this course
                $options['courseviewmine'] = array(
                    'string' => get_string('viewmyentriesaboutcourse', 'blog'),
                    'link' => new moodle_url('/blog/index.php', array('courseid'=>$course->id, 'userid'=>$USER->id))
                );

                if (!empty($user) && ($CFG->bloglevel >= BLOG_SITE_LEVEL)) {
                    // View the provided users entries about this course
                    $options['courseviewuser'] = array(
                        'string' => get_string('viewentriesbyuseraboutcourse', 'blog', fullname($user)),
                        'link' => new moodle_url('/blog/index.php', array('courseid'=>$course->id, 'userid'=>$user->id))
                    );
                }
            }
        }

        if (has_capability('moodle/blog:create', $sitecontext) and $canparticipate) {
            // We can blog about this course
            $options['courseadd'] = array(
                'string' => get_string('blogaboutthiscourse', 'blog'),
                'link' => new moodle_url('/blog/edit.php', array('action'=>'add', 'courseid'=>$course->id))
            );
        }

        // Cache the options for this course
        $courseoptions[$key] = $options;
        // Return the options
        return $options;
    }

    function get_blog_options_for_module($module, $user=null) {
        global $CFG, $USER;
        // Cache
        static $moduleoptions = array();

        $options = array();
        // User must be logged in, blogs must be enabled
        if (!($this->blog_is_enabled_for_user())) {
            return $options;
        }

        // Check the user can associate with the module
        $modcontext = context_module::instance($module->id);
        $sitecontext = context_system::instance();
        if (!has_capability('moodle/blog:associatemodule', $modcontext)) {
            return $options;
        }

        // Generate the cache key
        $key = $module->id.':';
        if (!empty($user)) {
            $key .= $user->id;
        } else {
            $key .= $USER->id;
        }
        if (array_key_exists($key, $moduleoptions)) {
            // Serve from the cache so we don't have to regenerate
            return $moduleoptions[$module->id];
        }

        $canparticipate = (is_enrolled($modcontext) or is_viewing($modcontext));

        if (has_capability('moodle/blog:view', $modcontext)) {
            // Save correct module name for later usage.
            $modulename = get_string('modulename', $module->modname);

            // We can view!
            if ($CFG->bloglevel >= BLOG_SITE_LEVEL) {
                $cm = get_coursemodule_from_id($module->modname, $module->id);
                $groupmode = groups_get_activity_groupmode($cm);

                // View all entries about this module   
                if ($groupmode != SEPARATEGROUPS || has_capability('mod/assign:grade', $modcontext)) {
                    $a = new stdClass;
                    $a->type = $modulename;
                    $options['moduleview'] = array(
                        'string' => get_string('viewallmodentries', 'blog', $a),
                        'link' => new moodle_url('/blog/index.php', array('modid'=>$module->id))
                    );
                }

                // If the course uses groups and the user is member of a group, let the user read all entries about this module by his group.
                if ($groupmode > NOGROUPS && ($groupid = groups_get_activity_group($cm)) && groups_is_member($groupid, $USER->id)) {
                    $a = new stdClass;
                    $a->mod = $modulename;
                    $a->user = groups_get_group_name($groupid);
                    $options['moduleviewgroup'] = array(
                        'string' => get_string('blogentriesbyuseraboutmodule', 'blog', $a),
                        'link' => new moodle_url('/blog/index.php', array(
                            'modid' => $module->id,
                            'groupid' => $groupid
                        ))  
                    );
            
                // If the course uses groups, but the user is not member of a group and also capable of grading, 
                // let the user read entries by all groups.
                } else if ($groupmode > NOGROUPS && has_capability('mod/assign:grade', $modcontext)) {
                    //TODO:DEBUG
                    echo "User with grading capabilities detected which isn't in a group \n";

                    foreach (groups_get_all_groups($cm->course) as $groupid => $group) {
                        $a = new stdClass;
                        $a->mod = $modulename;
                        $a->user = groups_get_group_name($groupid);
                        $options['moduleviewgroup'.$groupid] = array(
                            'string' => get_string('blogentriesbyuseraboutmodule', 'blog', $a),
                            'link' => new moodle_url('/blog/index.php', array(
                                'modid' => $module->id,
                                'groupid' => $groupid
                            ))
                        );
                    }
                }
            }

            // View MY entries about this module
            $options['moduleviewmine'] = array(
                'string' => get_string('viewmyentriesaboutmodule', 'blog', $modulename),
                'link' => new moodle_url('/blog/index.php', array('modid'=>$module->id, 'userid'=>$USER->id))
            );

            if (!empty($user) && ($CFG->bloglevel >= BLOG_SITE_LEVEL)) {
                // View the given users entries about this module
                $a = new stdClass;
                $a->mod = $modulename;
                $a->user = fullname($user);
                $options['moduleviewuser'] = array(
                    'string' => get_string('blogentriesbyuseraboutmodule', 'blog', $a),
                    'link' => new moodle_url('/blog/index.php', array('modid'=>$module->id, 'userid'=>$user->id))
                );
            }
        }

        if (has_capability('moodle/blog:create', $sitecontext) and $canparticipate) {
            // The user can blog about this module
            $options['moduleadd'] = array(
                'string' => get_string('blogaboutthismodule', 'blog', $modulename),
                'link' => new moodle_url('/blog/edit.php', array('action'=>'add', 'modid'=>$module->id))
            );
        }
        // Cache the options
        $moduleoptions[$key] = $options;
        // Return the options
        return $options;
    }
}