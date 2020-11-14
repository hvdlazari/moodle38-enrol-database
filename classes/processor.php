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
 * File containing processor class.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require($CFG->dirroot."/enrol/database/classes/helper.php");
require($CFG->dirroot."/enrol/database/classes/course.php");

defined('MOODLE_INTERNAL') || die();

/**
 * Processor class.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_database_processor {

    /**
     * Create courses that do not exist yet.
     */
    const MODE_CREATE_NEW = 1;

    /**
     * @var object
     */
    protected $newcourse;

    /** @var string */
    protected $templatecourse;

    /** @var int processor mode. */
    protected $mode;

    /**
     * Constructor
     *
     * @param object $newcourse newcourse of the process
     * @param $templatecourse
     */
    public function __construct($newcourse, $templatecourse) {
        $this->newcourse = $newcourse;
        $this->templatecourse = $templatecourse;

        $this->mode = self::MODE_CREATE_NEW;
    }

    /**
     * Execute the process.
     *
     * @return object
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @throws restore_controller_exception
     */
    public function execute() {
        // We will most certainly need extra time and memory to process big files.
        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        $data = $this->parse_data($this->newcourse);
        $course = $this->get_course($data);
        if ($course->prepare()) {
            $course->proceed();

            $data = array_merge($data, $course->get_data(), array('id' => $course->get_id()));
        }
        return (object) $data;
    }

    /**
     * Return a course import object.
     *
     * @param array $data data to import the course with.
     * @return enrol_database_course
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function get_course($data) {
        $importoptions = array(
            'restoredir' => $this->get_restore_content_dir(),
            'shortnametemplate' => $this->templatecourse
        );
        return new enrol_database_course($this->mode, $data, $importoptions);
    }

    /**
     * Get the directory of the object to restore.
     *
     * @return string subdirectory in $CFG->backuptempdir/...
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function get_restore_content_dir() {
        return enrol_database_helper::get_restore_content_dir(null, $this->templatecourse);
    }

    /**
     * Parse a line to return an array(column => value)
     *
     * @param object $templatedata returned by csv_import_reader
     * @return array
     */
    protected function parse_data($templatedata) {
        $data = array();
        foreach ($templatedata as $keynum => $value) {
            if (empty($templatedata->{$keynum})) {
                continue;
            }
            $data[$keynum] = $value;
        }
        return $data;
    }
}
