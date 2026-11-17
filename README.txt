EDUSPHERE PORTAL - XAMPP PACKAGE
================================
Copy this whole folder to  C:\xampp\htdocs\edusphere\

FILES
  index.html     the student portal (use THIS one, not oneindex.html: only this one talks to the server)
  js/app.js      all portal JavaScript          css/style.css   all portal styles
  api.php        the portal's connection to the database (keep the name in lowercase)
  admin.php      the administrator dashboard
  db.php         database settings + shared safety helpers
  database.sql   fresh install (creates the "edusphere" database)
  upgrade.sql    only for people who already imported an older database.sql (keeps their data)
  .htaccess      stops browsers from downloading db.php and the .sql files

SET UP (first time)
  1. XAMPP control panel: Start Apache and MySQL.
  2. http://localhost/phpmyadmin -> Import tab -> choose database.sql -> Go.
     (Already imported an older version? Select the edusphere database -> Import -> upgrade.sql instead.)
  3. Open db.php: change ADMIN_SETUP_KEY to a private word (and DB_PASS if you gave MySQL root a password).
  4. http://localhost/edusphere/admin.php -> enter the setup key -> create the first admin.
  5. Students: http://localhost/edusphere/index.html

WHAT THE ADMIN CAN DO (admin.php)
  Overview, Courses, Units, Resources, News, Timetable (weekly grid + class times), CATs and exams, Fees,
  Students (details, password reset, remove "studied" marks with a reason and lock them, block, delete),
  Applications, Messages, Activity log (filter + CSV), Settings, Admins.
The header, menu and countdown bar stay fixed at the top while you scroll.
