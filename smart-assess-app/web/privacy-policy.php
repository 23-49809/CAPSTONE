<?php
declare(strict_types=1);

require_once __DIR__ . '/legal-layout.php';

sa_legal_header(
    'Privacy Policy',
    "How SMART ASSESS, the online service of the Municipal Assessor's Office of Mabini, Batangas, collects, uses, stores and shares personal information, including its use of the Gmail API.",
    'November 6, 2026'
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
  <li><strong>Uploaded documents:</strong> depending on the service, a valid ID, an authorization letter, a Certified True Copy of a Tax Declaration or Title, a Notarial Deed of Sale or Donation, a Vicinity Map, a Tax Clearance, and a Certification of No Improvement. Files may be JPG, PNG, WEBP or PDF, up to 5 MB each.</li>
  <li><strong>Request records:</strong> your reference number, status history, processing times, the staff member assigned, any rejection reason, and messages staff send you with their delivery status.</li>
  <li><strong>Terms acceptance records:</strong> when you accepted the Terms of Service, which version, the application type, and a one-way hash derived from your connection's IP address.</li>
  <li><strong>Saved applications:</strong> the information and files you enter in an unfinished application, so that you can continue later.</li>
</ul>
<p>Staff, administrator and department head accounts also hold a name, username, password hash and work contact details. Sign-ins record the time, IP address and browser details.</p>

<h2 id="use">2. How we use it</h2>
<ul>
  <li>To receive, review and process your request or application.</li>
  <li>To verify your information and documents and identify the property involved. Staff review documents manually; the system does not read uploaded documents automatically.</li>
  <li>To show your request's status in request tracking.</li>
  <li>To contact you about your request when staff choose to send you a message.</li>
  <li>To keep the Office's transaction records and produce internal reports and statistics.</li>
  <li>To secure the system, for example by limiting repeated attempts and recording account activity.</li>
</ul>
<p>We do not sell personal information and do not use it for advertising. The public site loads no advertising or analytics services.</p>

<h2 id="access">3. Who can access it</h2>
<ul>
  <li><strong>Staff</strong> of the Office review and process requests, and can view your details and uploaded documents.</li>
  <li><strong>Administrator and department head accounts</strong> can access request information for their oversight and reporting duties. Uploaded files are opened through a viewer limited to Staff accounts.</li>
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
    <tr><td>Amazon Web Services</td><td>Hosts the website and its stored information on a server in the Singapore region, outside the Philippines</td><td>Everything stored in SMART ASSESS</td></tr>
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
  <li>Message-provider tokens and credentials are kept in server configuration or private files, not in the website's public pages.</li>
</ul>
<p>No system is completely secure, and we cannot guarantee absolute security. If you think your information has been accessed without permission, contact the Office right away.</p>

<h2 id="retention">9. How long information is kept</h2>
<ul>
  <li><strong>Saved applications not submitted:</strong> set to expire 7 days after your last change, and no later than 30 days after they were started. Expired applications and their files are then removed.</li>
  <li><strong>Submitted applications:</strong> the saved copy is cleared on submission; a small receipt record is kept for about one day to catch duplicate submissions.</li>
  <li><strong>Connection-based rate-limit records:</strong> removed after about one day.</li>
  <li><strong>Submitted requests, uploaded documents, status history, messages, and Terms acceptance records:</strong> SMART ASSESS does not delete these automatically, and the Office has not published a fixed retention period in this system. They are kept as the Office's transaction records, subject to applicable records rules. You may ask the Office about the retention of your records.</li>
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
