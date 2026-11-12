<?php
declare(strict_types=1);

require_once __DIR__ . '/legal-layout.php';
// Only to read and format the configured AI effective date; loads no database or session.
require_once dirname(__DIR__) . '/app/services/AiVerification.php';

/*
 * CONFIRM BEFORE PUBLICATION (these PHP comments are never sent to visitors;
 * the full list is in docs/AI_DOCUMENT_VERIFICATION.md, "Privacy Policy review"):
 *  1. The Office has reviewed and approved this text, including section 2's
 *     "Automatic document check".
 *  2. The date below is the date the reviewed text goes live.
 *  3. The website server and the document-check server are both in the AWS
 *     Asia Pacific (Sydney) Region (ap-southeast-2).
 *  4. The document-check server has no public internet address and accepts
 *     connections only from the website server.
 *  5. Publish the automatic-check text no later than the day AI checking is
 *     turned on (the migration and the dispatcher timer).
 *  6. The worker's SMART_ASSESS_WORKER_OLLAMA_URL is its own loopback address
 *     (http://127.0.0.1:11434), so "not sent to outside AI services" holds.
 *  7. Temporary request-body files of the worker's web server and PHP (nginx
 *     client_body_temp, PHP temp folder) are on the worker's encrypted disk;
 *     this text makes no promise about them beyond "kept only to check".
 *  8. Decided: requests submitted before the AI check's effective date and
 *     time are not checked by AI, automatically or by Staff. The server
 *     enforces it with SMART_ASSESS_AI_PRIVACY_EFFECTIVE_AT, set to the same
 *     value on the website and the dispatcher. The "Automatic document check"
 *     section below prints that configured value itself; it is separate from
 *     the "Last updated" date (item 2). Unset or invalid: the section says the
 *     check is not enabled, and nothing is checked.
 */
// The AI check's effective date and time, from the same setting the application enforces.
$saAiEffectiveFrom = AiVerification::privacyEffectiveFrom();
$saAiEffectiveText = $saAiEffectiveFrom === null ? null
    : (new DateTimeImmutable('@' . $saAiEffectiveFrom))->setTimezone(new DateTimeZone('+08:00'))->format('F j, Y, g:i:s A') . ' Philippine time (UTC+08:00)';
sa_legal_header(
    'Privacy Policy',
    "How SMART ASSESS, the online service of the Municipal Assessor's Office of Mabini, Batangas, collects, uses, stores and shares personal information, including its use of the Gmail API and its automatic document check.",
    'November 12, 2026'
);
?>
<nav class="legal-toc" aria-labelledby="toc-title">
  <h2 id="toc-title">Contents</h2>
  <ol>
    <li><a href="#collect">Information we collect</a></li>
    <li><a href="#use">How we use it</a></li>
    <li><a href="#access">Who can access it</a></li>
    <li><a href="#tracking">Reference numbers and tracking</a></li>
    <li><a href="#providers">Service providers</a></li>
    <li><a href="#notifications">SMS and email notifications</a></li>
    <li><a href="#google">Google user data and the Gmail API</a></li>
    <li><a href="#security">How information is protected</a></li>
    <li><a href="#retention">How long information is kept</a></li>
    <li><a href="#browser">Cookies and browser storage</a></li>
    <li><a href="#rights">Your rights</a></li>
    <li><a href="#contact">Contact and privacy concerns</a></li>
    <li><a href="#changes">Changes to this policy</a></li>
  </ol>
</nav>
<article class="legal-article">
<p>SMART ASSESS is the online system of the Municipal Assessor's Office of Mabini, Batangas (the "Office") for property document requests and land transfer applications. This policy explains what information the system handles, why, who can access it, and what choices you have.</p>

<h2 id="collect">1. Information we collect</h2>
<p>You do not need an account to apply. When you submit a document request or land transfer application, the system collects:</p>
<ul>
  <li><strong>Name and contact details:</strong> first, middle and last name; mobile number; email address (optional); and home address, including barangay, city, province and ZIP code.</li>
  <li><strong>Property and ownership information:</strong> ARP / Tax Declaration number, property address and barangay, and whether you are the registered owner or applying on the owner's behalf.</li>
  <li><strong>Request details:</strong> the document or type of transfer requested and its purpose.</li>
  <li><strong>Uploaded documents:</strong> depending on the service, a valid ID, an authorization letter, a Certified True Copy of a Tax Declaration or Title, a Notarial Deed of Sale or Donation, a Vicinity Map, a Tax Clearance, and a Certification of No Improvement. Each file must be smaller than 5 MiB. Government-issued IDs and authorization letters must be JPG or PNG; other documents may be JPG, PNG, WEBP or PDF.</li>
  <li><strong>Request records:</strong> your reference number, status history, processing times, the staff member assigned, any rejection reason, and messages staff send you with their delivery status.</li>
  <li><strong>Terms acceptance records:</strong> when you accepted the Terms of Service, which version, the application type, and a one-way hash derived from your connection's IP address.</li>
  <li><strong>Saved applications:</strong> the information and files you enter in an unfinished application, so that you can continue later.</li>
</ul>
<p>Staff, administrator and department head accounts also hold a name, username, password hash and work contact details. Sign-ins record the time, IP address and browser details.</p>

<h2 id="use">2. How we use it</h2>
<ul>
  <li>To receive, review and process your request or application.</li>
  <li>To verify your information and documents and identify the property involved. Staff review your documents. An automatic document check also reads them and points out possible problems to Staff; it decides nothing (see below).</li>
  <li>To show your request's status in request tracking.</li>
  <li>To contact you about your request when staff choose to send you a message.</li>
  <li>To keep the Office's transaction records and produce internal reports and statistics.</li>
  <li>To secure the system, for example by limiting repeated attempts and recording account activity.</li>
</ul>
<p>We do not sell personal information and do not use it for advertising. The public site loads no advertising or analytics services.</p>

<h3 id="document-check">Automatic document check</h3>
<?php if ($saAiEffectiveText !== null): ?>
<p>After you submit a request, SMART ASSESS checks the uploaded documents automatically to help Staff review them.</p>
<p class="legal-note"><strong>Effective date of the automatic document check:</strong> <?= htmlspecialchars($saAiEffectiveText, ENT_QUOTES, 'UTF-8') ?>. Only requests submitted on or after this date and time are checked; requests submitted earlier are not checked by AI. This date is separate from the "Last updated" date at the top of this page, which changes whenever any part of this policy is revised.</p>
<?php else: ?>
<p>SMART ASSESS has an automatic document check, described below, to help Staff review uploaded documents.</p>
<p class="legal-note"><strong>The automatic document check is not yet enabled:</strong> its effective date has not been established, so no request is checked by AI. When it is enabled, its effective date and time will be shown here, and only requests submitted on or after it will be checked. This is separate from the "Last updated" date at the top of this page.</p>
<?php endif; ?>
<ul>
  <li><strong>What it does:</strong> first, the website's own fixed rules check which documents your type of application requires and whether each one was uploaded in an accepted file type; no AI is used for this step. Then, on a separate document-check server, the text of the uploaded documents is extracted: text already stored inside a PDF is read directly, and images and scanned PDF pages are read with optical character recognition (OCR) software. Fixed rules compare that text with your surname and the ARP / Tax Declaration number you entered, and a language model looks for other possible problems, such as documents that do not match each other, a document that does not look like the expected type, or a detail that seems to be missing.</li>
  <li><strong>Limits:</strong> up to 10 documents per request, and the first 10 pages of each PDF, are checked. A file that cannot be read, an image larger than 25 megapixels, or a document that does not fit in the time allowed for one check is not read automatically; it is marked for Staff to inspect by hand. Not every file goes through OCR, and text extraction can miss or misread words.</li>
  <li><strong>What is sent for the check:</strong> the files uploaded with that request, your first and last name, the ARP / Tax Declaration number, the type of application, whether you are the registered owner, and the document or type of transfer requested. The mobile number, email address and home address you typed in are not sent, although an uploaded document such as an ID may itself show some of them.</li>
  <li><strong>Where it runs:</strong> on a separate server operated for the Office in the same Amazon Web Services region as the website (see section 5). <?php /* CONFIRM item 3 and 4 */ ?>The website sends each check to that server over an encrypted, authenticated connection. Documents are not sent to outside AI services, and the model is not trained with your information.</li>
  <li><strong>What is kept:</strong> the document-check server keeps your files only to check them, and normally deletes them when the check ends. If a check is interrupted, for example because that server or its software stops unexpectedly, files can remain on it for a while: they are deleted automatically the next time the server starts a check or is asked whether it is ready, and if they cannot be deleted, the server stops taking checks until an administrator removes them. SMART ASSESS stores the result with your request: the findings, a short quoted excerpt where a finding needs one, which checks ran, and the model and OCR software used. The full text read from your documents is not stored.</li>
  <li><strong>Who sees the result:</strong> Staff only. It is not shown to you, to administrator or department head accounts, or in request tracking.</li>
  <li><strong>Staff decide:</strong> the result is advice. The check does not approve or reject requests, change a request's status, or send you messages, and it cannot tell whether a document, signature or seal is genuine. It can be wrong. Staff open your original documents and make every decision.</li>
</ul>

<h2 id="access">3. Who can access it</h2>
<ul>
  <li><strong>Staff</strong> of the Office review and process requests, and can view your details and uploaded documents.</li>
  <li><strong>Administrator and department head accounts</strong> can access request information for their oversight and reporting duties. Uploaded files are opened through a viewer limited to Staff accounts, and automatic document check results are shown only to Staff.</li>
  <li><strong>People who maintain the server</strong> may have technical access to stored information when keeping the system running.</li>
</ul>
<p>We may also disclose information when the law or a competent authority requires it.</p>

<h2 id="tracking">4. Reference numbers and tracking</h2>
<p>Request tracking does not require signing in. <strong>Anyone who has your reference number can see the applicant's name, the service requested and the request's status.</strong> Keep your reference number, and any recovery code or resume link for a saved application, private.</p>

<h2 id="providers">5. Service providers</h2>
<p>We share information only as far as these services need it to work:</p>
<div class="legal-table-wrap"><table>
  <thead><tr><th scope="col">Provider</th><th scope="col">Purpose</th><th scope="col">Information involved</th></tr></thead>
  <tbody>
    <?php /* CONFIRM item 3: both servers are in ap-southeast-2 (Sydney). */ ?>
    <tr><td>Amazon Web Services</td><td>Hosts the website, its stored information and the automatic document check on servers in the Asia Pacific (Sydney) region, in Australia, outside the Philippines</td><td>Everything stored in SMART ASSESS, and the documents and details sent for the automatic document check</td></tr>
    <tr><td>IPROG SMS</td><td>Delivers SMS messages that staff send</td><td>Your mobile number and the message text</td></tr>
    <tr><td>Google (Gmail API)</td><td>Delivers email messages that staff send</td><td>Your name, email address, reference number and the message text</td></tr>
  </tbody>
</table></div>
<p>These providers handle information under their own terms and privacy policies.</p>

<h2 id="notifications">6. SMS and email notifications</h2>
<p>Submitting a request or changing its status does not send you an SMS or email. Status changes appear in request tracking. An SMS or email is sent only when a staff member chooses to send a message about your request from the Notifications page, to the number or address on that request.</p>
<p>The message text and its delivery status are kept with your request. A provider accepting a message does not guarantee that it reaches you.</p>

<h2 id="google">7. Google user data and the Gmail API</h2>
<p><strong>Applicants never sign in with Google, and SMART ASSESS does not access applicants' Google accounts.</strong> The only Google account connected is the one the Office authorizes for sending its messages.</p>
<ul>
  <li><strong>Permission requested:</strong> only <code>https://www.googleapis.com/auth/gmail.send</code>, which allows sending email from that account.</li>
  <li><strong>What is accessed:</strong> SMART ASSESS uses this permission only to send staff-written messages. It does not read, search, list, change or delete messages, and does not access contacts, attachments or other Google data.</li>
  <li><strong>How it is used:</strong> to send a message about a request to the email address on that request, when staff click Send.</li>
  <li><strong>What is stored:</strong> the OAuth authorization tokens, kept on the server in a folder limited to the web server account. Sent messages also appear in the Sent folder of the Office's Gmail account. SMART ASSESS stores no data read from Gmail, because it reads none.</li>
  <li><strong>What is shared:</strong> the tokens are not shared with anyone. Each message goes to Google for delivery and to its recipient. Google user data is not sold, used for advertising, or used to train artificial intelligence models.</li>
  <li><strong>Ending access:</strong> the account owner can revoke access at any time in <a href="https://myaccount.google.com/permissions" rel="noopener">Google Account permissions</a>.</li>
</ul>
<p class="legal-note">SMART ASSESS's use and transfer to any other app of information received from Google APIs will adhere to the <a href="https://developers.google.com/terms/api-services-user-data-policy" rel="noopener">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>

<h2 id="security">8. How information is protected</h2>
<p>The measures currently in place include:</p>
<ul>
  <li>Connections to the public website use HTTPS.</li>
  <li>Staff, administrator and department head pages require signing in, and access depends on the account's role. Passwords are stored as hashes.</li>
  <li>Uploaded files are stored outside the public web folder, checked by file type on upload, and opened only through the Staff-only viewer.</li>
  <?php /* CONFIRM item 4: the worker has no public address and its security group allows only the website server. */ ?>
  <li>The automatic document check runs on a server that is not reachable from the internet. The website and that server authenticate every check, and the connection between them is encrypted.</li>
  <li>Message-provider tokens and credentials are kept in server configuration or private files, not in the website's public pages.</li>
</ul>
<p>No system is completely secure, and we cannot guarantee absolute security. If you think your information has been accessed without permission, contact the Office right away.</p>

<h2 id="retention">9. How long information is kept</h2>
<ul>
  <li><strong>Saved applications not submitted:</strong> set to expire 7 days after your last change, and no later than 30 days after they were started. Expired applications and their files are then removed.</li>
  <li><strong>Submitted applications:</strong> the saved copy is cleared on submission; a small receipt record is kept for about one day to catch duplicate submissions.</li>
  <li><strong>Connection-based rate-limit records:</strong> removed after about one day.</li>
  <li><strong>Automatic document check:</strong> the document-check server normally deletes the files when the check ends; after an interrupted check, they are deleted by the automatic cleanup described in section 2. The result is kept with the request, as described in the next item.</li>
  <li><strong>Submitted requests, uploaded documents, automatic document check results, status history, messages, and Terms acceptance records:</strong> SMART ASSESS does not delete these automatically, and the Office has not published a fixed retention period in this system. They are kept as the Office's transaction records, subject to applicable records rules. You may ask the Office about the retention of your records.</li>
</ul>

<h2 id="browser">10. Cookies and browser storage</h2>
<p>The site uses one session cookie, which keeps the site working and keeps staff signed in; it is not used for tracking across websites. Your browser's local storage may hold your unfinished-application details and recovery code, and which announcements you have read. On a shared device, clear this data or finish your application.</p>

<h2 id="rights">11. Your rights</h2>
<p>Under the Data Privacy Act of 2012 (Republic Act No. 10173), you may have the right to:</p>
<ul>
  <li>be informed about how your personal information is processed;</li>
  <li>access your personal information and get a copy of it;</li>
  <li>have inaccurate information corrected;</li>
  <li>object to processing, and ask for your information to be blocked or erased, where applicable;</li>
  <li>be indemnified for damages as the law provides; and</li>
  <li>file a complaint with the National Privacy Commission.</li>
</ul>
<p>Some requests may be limited where the Office must keep records by law or to carry out its functions. SMART ASSESS has no self-service option for these requests; send them to the Office. The Office may need to confirm your identity first.</p>

<h2 id="contact">12. Contact and privacy concerns</h2>
<p>For questions, requests or concerns about your personal information, including suspected unauthorized access, contact:</p>
<p>Municipal Assessor's Office<br>Municipal Hall, Poblacion, Mabini, Batangas, Philippines<br>Phone: (043) 487-0123<br>Email: <a href="mailto:mao.smartassess@gmail.com">mao.smartassess@gmail.com</a><br>Office hours: Monday to Friday, 8:00 AM to 5:00 PM</p>
<p>If your concern is not resolved, you may contact the <a href="https://privacy.gov.ph/" rel="noopener">National Privacy Commission</a>.</p>

<h2 id="changes">13. Changes to this policy</h2>
<p>We may update this policy when the system, the Office's procedures or the law changes. The date at the top shows the latest update.</p>
<p>See also the <a href="/terms.php">Terms of Service</a>.</p>
<?php
sa_legal_footer();
