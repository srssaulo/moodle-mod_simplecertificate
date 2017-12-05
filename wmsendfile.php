<?php
/**
 * Watermark and send files
 * 
 * @package mod
 * @subpackage simplecertificate
 * @copyright 2014 © Carlos Alexandre Soares da Fonseca
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
// ... $id = required_param('id', PARAM_INTEGER); // Issed Code.
// ... $sk = required_param('sk', PARAM_RAW); // sesskey.
$code = required_param('code', PARAM_TEXT); // Issued Code.


if (!$issuedcert = $DB->get_record("simplecertificate_issues", array('code' => $code))) {
    print_error(get_string('issuedcertificatenotfound', 'simplecertificate'));
} else {
    send_certificate_file($issuedcert);
}

function send_certificate_file(stdClass $issuedcert) {
    global $CFG, $USER, $DB, $PAGE;

    if ($issuedcert->haschange) {
        //This issue have a haschange flag, try to reissue
        if (empty($issuedcert->timedeleted)) {
            require_once ($CFG->dirroot . '/mod/simplecertificate/locallib.php');
            try {
                // Try to get cm
                $cm = get_coursemodule_from_instance('simplecertificate', $issuedcert->certificateid, 0, false, MUST_EXIST);

                $context = context_module::instance($cm->id);
                
                //Must set a page context to issue .... 
                $PAGE->set_context($context);
                $simplecertificate = new simplecertificate($context, null, null);
                $file = $simplecertificate->get_issue_file($issuedcert);
            
            } catch (moodle_exception $e) {
                // Only debug, no errors
                debugging($e->getMessage(), DEBUG_DEVELOPER, $e->getTrace());
            }
        } else {
            //Have haschange and timedeleted, somehting wrong, it will be impossible to reissue
            //add wraning
            debugging("issued certificate [$issuedcert->id], have haschange and timedeleted");
        }
        $issuedcert->haschange = 0;
        $DB->update_record('simplecertificate_issues', $issuedcert);
    }
    
    if (empty($file)) {
        $fs = get_file_storage();
        if (!$fs->file_exists_by_hash($issuedcert->pathnamehash)) {
            print_error(get_string('filenotfound', 'simplecertificate', ''));
        }
        
        $file = $fs->get_file_by_hash($issuedcert->pathnamehash);
    }
    
    $canmanage = false;
    if ($cm = get_coursemodule_from_instance('simplecertificate', $issuedcert->certificateid)) {
        $canmanage = has_capability('mod/simplecertificate:manage', context_course::instance($cm->course));
    }
    
    if ($canmanage || (!empty($USER) && $USER->id == $issuedcert->userid)) {
        // If logged in it's owner of this certificate, or has can manage the course
        // will send the certificate without watermark.
        send_stored_file($file, 0, 0, true);
    } else {
        // If no login or it's not certificate owner and don't have manage privileges
        // it will put a 'copy' watermark and send the file.
        $wmfile = put_watermark($file);
        send_temp_file($wmfile, $file->get_filename());
    }
}

/**
 * @param file
 */
function put_watermark($file) {
    global $CFG;

    require_once($CFG->libdir.'/pdflib.php');
    require_once($CFG->dirroot.'/mod/assign/feedback/editpdf/fpdi/fpdi.php');

    // Copy to a tmp file.
    $tmpfile = $file->copy_content_to_temp();

    // TCPF doesn't import files yet, so i must use FPDI.
    $pdf = new FPDI();
    $pagecount = $pdf->setSourceFile($tmpfile);

    for ($pgnum = 1; $pgnum <= $pagecount; $pgnum++) {
        // Import a page.
        $templateid = $pdf->importPage($pgnum);
        // Get the size of the imported page.
        $size = $pdf->getTemplateSize($templateid);

        // Create a page (landscape or portrait depending on the imported page size).
        if ($size['w'] > $size['h']) {
            $pdf->AddPage('L', array($size['w'], $size['h']));
            // Font size 1/3 Height if it landscape.
            $fontsize = $size['h'] / 3;
        } else {
            $pdf->AddPage('P', array($size['w'], $size['h']));
            // Font size 1/3 Width if it portrait.
            $fontsize = $size['w'] / 3;
        }

        // Use the imported page.
        $pdf->useTemplate($templateid);

        // Calculating the rotation angle.
        $rotangle = (atan($size['h'] / $size['w']) * 180) / pi();
        // Find the middle of the page to use as a pivot at rotation.
        $mdlx = ($size['w'] / 2);
        $mdly = ($size['h'] / 2);

        // Set the transparency of the text to really light.
        $pdf->SetAlpha(0.25);

        $pdf->StartTransform();
        $pdf->Rotate($rotangle, $mdlx, $mdly);
        $pdf->SetFont("freesans", "B", $fontsize);

        $pdf->SetXY(0, $mdly);
        $bodersytle = array('LTRB' => array('width' => 2, 'dash' => $fontsize / 5,
                                    'cap' => 'round',
                                    'join' => 'round',
                                    'phase' => $fontsize / $mdlx)
        );

        $pdf->Cell($size['w'], $fontsize, get_string('certificatecopy', 'simplecertificate'), $bodersytle, 0, 'C', false, '',
                4, true, 'C', 'C');
        $pdf->StopTransform();

        // Reset the transparency to default.
        $pdf->SetAlpha(1);

    }
    // Set protection seems not work, but don't hurt.
    $pdf->SetProtection(array('print', 'modify',
                              'copy', 'annot-forms',
                              'fill-forms', 'extract',
                              'assemble', 'print-high'),
                        null,
                        random_string(5),
                        1,
                        null
    );

    // For DEGUG
    // $pdf->Output($file->get_filename(), 'I');.

    // Save and send tmpfiles.
    $pdf->Output($tmpfile, 'F');
    return $tmpfile;

}
