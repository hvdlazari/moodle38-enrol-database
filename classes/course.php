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
 * File containing the course class.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Course class.
 *
 * @package    enrol_database
 * @copyright  2020 Hellen Lazari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_database_course {

    /** Outcome of the process: creating the course */
    const DO_CREATE = 1;

    /** Outcome of the process: updating the course */
    const DO_UPDATE = 2;

    /** Outcome of the process: deleting the course */
    const DO_DELETE = 3;

    /** @var array final import data. */
    protected $data = array();

    /** @var array enrolment data. */
    protected $enrolmentdata;

    /** @var array errors. */
    protected $errors = array();

    /** @var int the ID of the course that had been processed. */
    protected $id;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches enrol_database_processor::MODE_* */
    protected $mode;

    /** @var array course import options. */
    protected $options = array();

    /** @var int constant value of self::DO_*, what to do with that course */
    protected $do;

    /** @var bool set to true once we have prepared the course */
    protected $prepared = false;

    /** @var bool set to true once we have started the process of the course */
    protected $processstarted = false;

    /** @var array course import data. */
    protected $rawdata = array();

    /** @var array restore directory. */
    protected $restoredata;

    /** @var string course shortname. */
    protected $shortname;

    /** @var array errors. */
    protected $statuses = array();

    /** @var array fields allowed as course data. */
    static protected $validfields = array('fullname', 'shortname', 'idnumber', 'category', 'visible', 'startdate', 'enddate',
        'summary', 'format', 'theme', 'lang', 'newsitems', 'showgrades', 'showreports', 'legacyfiles', 'maxbytes',
        'groupmode', 'groupmodeforce', 'enablecompletion');

    /** @var array fields required on course creation. */
    static protected $mandatoryfields = array('fullname', 'category');

    /** @var array fields which are considered as options. */
    static protected $optionfields = array('backupfile' => null,'templatecourse' => null);

    /** @var array options determining what can or cannot be done at an import level. */
    static protected $importoptionsdefaults = array('restoredir' => null, 'shortnametemplate' => null);

    /**
     * Constructor
     *
     * @param int $mode import mode, constant matching enrol_database_processor::MODE_*
     * @param array $rawdata raw course data.
     * @param array $importoptions import options.
     * @throws coding_exception
     */
    public function __construct($mode, $rawdata, $importoptions = array()) {

        if ($mode !== enrol_database_processor::MODE_CREATE_NEW) {
            throw new coding_exception('Incorrect mode.');
        }

        $this->mode = $mode;

        if (isset($rawdata['shortname'])) {
            $this->shortname = $rawdata['shortname'];
        }
        $this->rawdata = $rawdata;

        // Extract course options.
        foreach (self::$optionfields as $option => $default) {
            $this->options[$option] = isset($rawdata[$option]) ? $rawdata[$option] : $default;
        }

        // Import options.
        foreach (self::$importoptionsdefaults as $option => $default) {
            $this->importoptions[$option] = isset($importoptions[$option]) ? $importoptions[$option] : $default;
        }
    }

    /**
     * Does the mode allow for course creation?
     *
     * @return bool
     */
    public function can_create() {
        return $this->mode === enrol_database_processor::MODE_CREATE_NEW;
    }

    /**
     * Does the mode only allow for course creation?
     *
     * @return bool
     */
    public function can_only_create() {
        return $this->mode === enrol_database_processor::MODE_CREATE_NEW;
    }

    /**
     * Log an error
     *
     * @param string $code error code.
     * @param lang_string $message error message.
     * @return void
     * @throws coding_exception
     */
    protected function error($code, lang_string $message) {
        if (array_key_exists($code, $this->errors)) {
            throw new coding_exception('Error code already defined');
        }
        $this->errors[$code] = $message;
    }

    /**
     * Return whether the course exists or not.
     *
     * @param string $shortname the shortname to use to check if the course exists. Falls back on $this->shortname if empty.
     * @return bool
     * @throws dml_exception
     */
    protected function exists($shortname = null) {
        global $DB;
        if (is_null($shortname)) {
            $shortname = $this->shortname;
        }
        if (!empty($shortname) || is_numeric($shortname)) {
            return $DB->record_exists('course', array('shortname' => $shortname));
        }
        return false;
    }

    /**
     * Return the data that will be used upon saving.
     *
     * @return null|array
     */
    public function get_data() {
        return $this->data;
    }

    /**
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Assemble the course data based on defaults.
     *
     * This returns the final data to be passed to create_course().
     *
     * @param array $data current data.
     * @return array
     */
    protected function get_final_create_data($data) {
        $data['shortname'] = $this->shortname;
        return $data;
    }

    /**
     * Return the ID of the processed course.
     *
     * @return int|null
     * @throws coding_exception
     */
    public function get_id() {
        if (!$this->processstarted) {
            throw new coding_exception('The course has not been processed yet!');
        }
        return $this->id;
    }

    /**
     * Get the directory of the object to restore.
     *
     * @return string|false|null subdirectory in $CFG->backuptempdir/..., false when an error occured
     *                           and null when there is simply nothing.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function get_restore_content_dir() {
        $backupfile = null;
        $shortname = null;

        if (!empty($this->options['backupfile'])) {
            $backupfile = $this->options['backupfile'];
        } else if (!empty($this->options['templatecourse']) || is_numeric($this->options['templatecourse'])) {
            $shortname = $this->options['templatecourse'];
        }

        $errors = array();
        $dir = enrol_database_helper::get_restore_content_dir($backupfile, $shortname, $errors);
        if (!empty($errors)) {
            foreach ($errors as $key => $message) {
                $this->error($key, $message);
            }
            return false;
        } else if ($dir === false) {
            // We want to return null when nothing was wrong, but nothing was found.
            $dir = null;
        }

        if (empty($dir) && !empty($this->importoptions['restoredir'])) {
            $dir = $this->importoptions['restoredir'];
        }

        return $dir;
    }

    /**
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_statuses() {
        return $this->statuses;
    }

    /**
     * Return whether there were errors with this course.
     *
     * @return boolean
     */
    public function has_errors() {
        return !empty($this->errors);
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function prepare() {
        global $DB, $SITE;
        $this->prepared = true;

        // Validate the shortname.
        if (!empty($this->shortname) || is_numeric($this->shortname)) {
            if ($this->shortname !== clean_param($this->shortname, PARAM_TEXT)) {
                $this->error('invalidshortname', new lang_string('invalidshortname', 'enrol_database'));
                return false;
            }

            // Ensure we don't overflow the maximum length of the shortname field.
            if (core_text::strlen($this->shortname) > 255) {
                $this->error('invalidshortnametoolong', new lang_string('invalidshortnametoolong', 'enrol_database', 255));
                return false;
            }
        }

        $exists = $this->exists();

        // Can we create/update the course under those conditions?
        if ($exists) {
            if ($this->mode === enrol_database_processor::MODE_CREATE_NEW) {
                $this->error('courseexistsanduploadnotallowed',
                    new lang_string('courseexistsanduploadnotallowed', 'enrol_database'));
                return false;
            }
        } else {
            if (!$this->can_create()) {
                $this->error('coursedoesnotexistandcreatenotallowed',
                    new lang_string('coursedoesnotexistandcreatenotallowed', 'enrol_database'));
                return false;
            }
        }

        // Basic data.
        $coursedata = array();
        foreach ($this->rawdata as $field => $value) {
            if (!in_array($field, self::$validfields)) {
                continue;
            } else if ($field == 'shortname') {
                // Let's leave it apart from now, use $this->shortname only.
                continue;
            }
            $coursedata[$field] = $value;
        }

        // Resolve the category, and fail if not found.
        $errors = array();
        $catid = enrol_database_helper::resolve_category($this->rawdata, $errors);
        if (empty($errors)) {
            $coursedata['category'] = $catid;
        } else {
            foreach ($errors as $key => $message) {
                $this->error($key, $message);
            }
            return false;
        }

        // Ensure we don't overflow the maximum length of the fullname field.
        if (!empty($coursedata['fullname']) && core_text::strlen($coursedata['fullname']) > 254) {
            $this->error('invalidfullnametoolong', new lang_string('invalidfullnametoolong', 'enrol_database', 254));
            return false;
        }

        // Should we generate a shortname?
        if (empty($this->shortname) && !is_numeric($this->shortname)) {
            if (empty($this->importoptions['shortnametemplate'])) {
                $this->error('missingshortnamenotemplate', new lang_string('missingshortnamenotemplate', 'enrol_database'));
                return false;
            }
        }

        // If the course does not exist, ensure that the ID number is not taken.
        if (!$exists && isset($coursedata['idnumber'])) {
            if ($DB->count_records_select('course', 'idnumber = :idn', array('idn' => $coursedata['idnumber'])) > 0) {
                $this->error('idnumberalreadyinuse', new lang_string('idnumberalreadyinuse', 'enrol_database'));
                return false;
            }
        }

        // Course start date.
        if (!empty($coursedata['startdate'])) {
            $coursedata['startdate'] = strtotime($coursedata['startdate']);
        }

        // Course end date.
        if (!empty($coursedata['enddate'])) {
            $coursedata['enddate'] = strtotime($coursedata['enddate']);
        }

        // If lang is specified, check the user is allowed to set that field.
        if (!empty($coursedata['lang'])) {
            if ($exists) {
                $courseid = $DB->get_field('course', 'id', ['shortname' => $this->shortname]);
                if (!has_capability('moodle/course:setforcedlanguage', context_course::instance($courseid))) {
                    $this->error('cannotforcelang', new lang_string('cannotforcelang', 'enrol_database'));
                    return false;
                }
            } else {
                $catcontext = context_coursecat::instance($coursedata['category']);
                if (!guess_if_creator_will_have_course_capability('moodle/course:setforcedlanguage', $catcontext)) {
                    $this->error('cannotforcelang', new lang_string('cannotforcelang', 'enrol_database'));
                    return false;
                }
            }
        }

        if ($exists) {
            $this->error('courseexistsanduploadnotallowed',
                new lang_string('courseexistsanduploadnotallowed', 'enrol_database'));
            return false;
        }

        $coursedata = $this->get_final_create_data($coursedata);
        $this->do = self::DO_CREATE;

        // Validate course start and end dates.
        if ($exists) {
            // We also check existing start and end dates if we are updating an existing course.
            $existingdata = $DB->get_record('course', array('shortname' => $this->shortname));
            if (empty($coursedata['startdate'])) {
                $coursedata['startdate'] = $existingdata->startdate;
            }
            if (empty($coursedata['enddate'])) {
                $coursedata['enddate'] = $existingdata->enddate;
            }
        }

        if ($errorcode = course_validate_dates($coursedata)) {
            $this->error($errorcode, new lang_string($errorcode, 'error'));
            return false;
        }

        // Add role renaming.
        $errors = array();
        $rolenames = enrol_database_helper::get_role_names($this->rawdata, $errors);
        if (!empty($errors)) {
            foreach ($errors as $key => $message) {
                $this->error($key, $message);
            }
            return false;
        }
        foreach ($rolenames as $rolekey => $rolename) {
            $coursedata[$rolekey] = $rolename;
        }

        // Some validation.
        if (!empty($coursedata['format']) && !in_array($coursedata['format'], enrol_database_helper::get_course_formats())) {
            $this->error('invalidcourseformat', new lang_string('invalidcourseformat', 'enrol_database'));
            return false;
        }

        // Add data for course format options.
        if (isset($coursedata['format']) || $exists) {
            if (isset($coursedata['format'])) {
                $courseformat = course_get_format((object)['format' => $coursedata['format']]);
            } else {
                $courseformat = course_get_format($existingdata);
            }
            $coursedata += $courseformat->validate_course_format_options($this->rawdata);
        }

        // Special case, 'numsections' is not a course format option any more but still should apply from the template course,
        // if any, and otherwise from defaults.
        if (!$exists || !array_key_exists('numsections', $coursedata)) {
            if (isset($this->rawdata['numsections']) && is_numeric($this->rawdata['numsections'])) {
                $coursedata['numsections'] = (int)$this->rawdata['numsections'];
            } else if (isset($this->options['templatecourse'])) {
                $numsections = enrol_database_helper::get_coursesection_count($this->options['templatecourse']);
                if ($numsections != 0) {
                    $coursedata['numsections'] = $numsections;
                } else {
                    $coursedata['numsections'] = get_config('moodlecourse', 'numsections');
                }
            } else {
                $coursedata['numsections'] = get_config('moodlecourse', 'numsections');
            }
        }

        // Visibility can only be 0 or 1.
        if (!empty($coursedata['visible']) AND !($coursedata['visible'] == 0 OR $coursedata['visible'] == 1)) {
            $this->error('invalidvisibilitymode', new lang_string('invalidvisibilitymode', 'enrol_database'));
            return false;
        }

        // Saving data.
        $this->data = $coursedata;
        $this->enrolmentdata = enrol_database_helper::get_enrolment_data($this->rawdata);

        if (isset($this->rawdata['tags']) && strval($this->rawdata['tags']) !== '') {
            $this->data['tags'] = preg_split('/\s*,\s*/', trim($this->rawdata['tags']), -1, PREG_SPLIT_NO_EMPTY);
        }

        // Restore data.
        // TODO Speed up things by not really extracting the backup just yet, but checking that
        // the backup file or shortname passed are valid. Extraction should happen in proceed().
        $this->restoredata = $this->get_restore_content_dir();
        if ($this->restoredata === false) {
            return false;
        }

        return true;
    }

    /**
     * Proceed with the import of the course.
     *
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     * @throws restore_controller_exception
     * @throws Exception
     */
    public function proceed() {
        global $CFG, $USER;

        if (!$this->prepared) {
            throw new coding_exception('The course has not been prepared.');
        } else if ($this->has_errors()) {
            throw new moodle_exception('Cannot proceed, errors were detected.');
        } else if ($this->processstarted) {
            throw new coding_exception('The process has already been started.');
        }
        $this->processstarted = true;

        if ($this->do === self::DO_CREATE) {
            $course = create_course((object) $this->data);
            $this->id = $course->id;
            $this->status('coursecreated', new lang_string('coursecreated', 'enrol_database'));
        }
        else {
            // Strangely the outcome has not been defined, or is unknown!
            throw new coding_exception('Unknown outcome!');
        }

        // Restore a course.
        if (!empty($this->restoredata)) {
            $rc = new restore_controller($this->restoredata, $course->id, backup::INTERACTIVE_NO,
                backup::MODE_IMPORT, $USER->id, backup::TARGET_CURRENT_ADDING);

            // Check if the format conversion must happen first.
            if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
                $rc->convert();
            }
            if ($rc->execute_precheck()) {
                $rc->execute_plan();
                $this->status('courserestored', new lang_string('courserestored', 'enrol_database'));
            } else {
                $this->error('errorwhilerestoringcourse', new lang_string('errorwhilerestoringthecourse', 'enrol_database'));
            }
            $rc->destroy();
        }

        // Proceed with enrolment data.
        $this->process_enrolment_data($course);

        // Mark context as dirty.
        $context = context_course::instance($course->id);
        $context->mark_dirty();
    }

    /**
     * Add the enrolment data for the course.
     *
     * @param object $course course record.
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function process_enrolment_data($course) {
        global $DB;

        $enrolmentdata = $this->enrolmentdata;
        if (empty($enrolmentdata)) {
            return;
        }

        $enrolmentplugins = enrol_database_helper::get_enrolment_plugins();
        $instances = enrol_get_instances($course->id, false);
        foreach ($enrolmentdata as $enrolmethod => $method) {

            $instance = null;
            foreach ($instances as $i) {
                if ($i->enrol == $enrolmethod) {
                    $instance = $i;
                    break;
                }
            }

            $todelete = isset($method['delete']) && $method['delete'];
            $todisable = isset($method['disable']) && $method['disable'];
            unset($method['delete']);
            unset($method['disable']);

            if (!empty($instance) && $todelete) {
                // Remove the enrolment method.
                foreach ($instances as $instance) {
                    if ($instance->enrol == $enrolmethod) {
                        $plugin = $enrolmentplugins[$instance->enrol];
                        $plugin->delete_instance($instance);
                        break;
                    }
                }
            } else if (!empty($instance) && $todisable) {
                // Disable the enrolment.
                foreach ($instances as $instance) {
                    if ($instance->enrol == $enrolmethod) {
                        $plugin = $enrolmentplugins[$instance->enrol];
                        $plugin->update_status($instance, ENROL_INSTANCE_DISABLED);
                        $enrol_updated = true;
                        break;
                    }
                }
            } else {
                $plugin = null;
                if (empty($instance)) {
                    $plugin = $enrolmentplugins[$enrolmethod];
                    $instance = new stdClass();
                    $instance->id = $plugin->add_default_instance($course);
                    $instance->roleid = $plugin->get_config('roleid');
                    $instance->status = ENROL_INSTANCE_ENABLED;
                } else {
                    $plugin = $enrolmentplugins[$instance->enrol];
                    $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
                }

                // Now update values.
                foreach ($method as $k => $v) {
                    $instance->{$k} = $v;
                }

                // Sort out the start, end and date.
                $instance->enrolstartdate = (isset($method['startdate']) ? strtotime($method['startdate']) : 0);
                $instance->enrolenddate = (isset($method['enddate']) ? strtotime($method['enddate']) : 0);

                // Is the enrolment period set?
                if (isset($method['enrolperiod']) && ! empty($method['enrolperiod'])) {
                    if (preg_match('/^\d+$/', $method['enrolperiod'])) {
                        $method['enrolperiod'] = (int) $method['enrolperiod'];
                    } else {
                        // Try and convert period to seconds.
                        $method['enrolperiod'] = strtotime('1970-01-01 GMT + ' . $method['enrolperiod']);
                    }
                    $instance->enrolperiod = $method['enrolperiod'];
                }
                if ($instance->enrolstartdate > 0 && isset($method['enrolperiod'])) {
                    $instance->enrolenddate = $instance->enrolstartdate + $method['enrolperiod'];
                }
                if ($instance->enrolenddate > 0) {
                    $instance->enrolperiod = $instance->enrolenddate - $instance->enrolstartdate;
                }
                if ($instance->enrolenddate < $instance->enrolstartdate) {
                    $instance->enrolenddate = $instance->enrolstartdate;
                }

                // Sort out the given role. This does not filter the roles allowed in the course.
                if (isset($method['role'])) {
                    $roleids = enrol_database_helper::get_role_ids();
                    if (isset($roleids[$method['role']])) {
                        $instance->roleid = $roleids[$method['role']];
                    }
                }

                $instance->timemodified = time();
                $DB->update_record('enrol', $instance);
            }
        }
    }

    /**
     * Reset the current course.
     *
     * This does not reset any of the content of the activities.
     *
     * @param stdClass $course the course object of the course to reset.
     * @return array status array of array component, item, error.
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function reset($course) {
        global $DB;

        $resetdata = new stdClass();
        $resetdata->id = $course->id;
        $resetdata->reset_start_date = time();
        $resetdata->reset_events = true;
        $resetdata->reset_notes = true;
        $resetdata->delete_blog_associations = true;
        $resetdata->reset_completion = true;
        $resetdata->reset_roles_overrides = true;
        $resetdata->reset_roles_local = true;
        $resetdata->reset_groups_members = true;
        $resetdata->reset_groups_remove = true;
        $resetdata->reset_groupings_members = true;
        $resetdata->reset_groupings_remove = true;
        $resetdata->reset_gradebook_items = true;
        $resetdata->reset_gradebook_grades = true;
        $resetdata->reset_comments = true;

        if (empty($course->startdate)) {
            $course->startdate = $DB->get_field_select('course', 'startdate', 'id = :id', array('id' => $course->id));
        }
        $resetdata->reset_start_date_old = $course->startdate;

        if (empty($course->enddate)) {
            $course->enddate = $DB->get_field_select('course', 'enddate', 'id = :id', array('id' => $course->id));
        }
        $resetdata->reset_end_date_old = $course->enddate;

        // Add roles.
        $roles = enrol_database_helper::get_role_ids();
        $resetdata->unenrol_users = array_values($roles);
        $resetdata->unenrol_users[] = 0;    // Enrolled without role.

        return reset_course_userdata($resetdata);
    }

    /**
     * Log a status
     *
     * @param string $code status code.
     * @param lang_string $message status message.
     * @return void
     * @throws coding_exception
     */
    protected function status($code, lang_string $message) {
        if (array_key_exists($code, $this->statuses)) {
            throw new coding_exception('Status code already defined');
        }
        $this->statuses[$code] = $message;
    }

}
