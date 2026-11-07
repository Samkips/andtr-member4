# ANDtr Member 4

A localhost prototype for sharing and reviewing medical device findings. The included notices and accounts are sample data.

## Run on Windows with XAMPP

1. Extract the ZIP. Copy the entire `customer` folder into `C:\xampp\htdocs`. Check that `C:\xampp\htdocs\customer\index.php` exists.
2. In XAMPP Control Panel, start **Apache** and **MySQL**.
3. Open http://localhost/phpmyadmin/ in your browser. On the server home page choose **Import**, select `database.sql` from the project folder and click **Import** or **Go**. It creates and selects the `andtr_member4` database.
4. Open http://localhost/customer/.
5. If your MySQL password or port differs from the XAMPP defaults, copy `config.example.php` to `config.local.php` and edit the copy. PHP 8 with PDO MySQL and mbstring is required. If Apache uses a custom port, add it to both browser addresses.

## Local accounts

All three accounts use the local password `AndtrDemo2026!`.

| Role | Email |
| --- | --- |
| Employee | employee@example.test |
| Customer | customer@example.test |
| Second customer | customer2@example.test |

## Try it in this order

1. Sign in as the employee. Search for Battery overheating risk, tick its box, select City Hospital NHS Trust and click **Share findings**. Open the result under **Shared History**.
2. Sign out. Sign in as the first customer. Open the finding and check that the notice and employee message appear. Copy the detail URL.
3. Post a comment. Sign back in as the employee and open the share to check that the comment appears.
4. Sign in as the second customer and paste the copied detail URL. The page should say **Access denied**.
5. Run the manual cases in the report. Record the actual results and screenshots from your own run. For the 501-character case, the browser caps input at 500. Use browser developer tools to increase the textarea's maxlength temporarily if you want to check the independent server validation. For role and comment access tests, distinguish blocked UI actions from direct HTTP requests.

## Files

| File | Purpose |
| --- | --- |
| index.php | Form handling, access checks, database queries and page rendering |
| style.css | Page styling |
| database.sql | Tables and demo data |
| config.example.php | Default localhost connection settings |

This implementation uses a single PHP entry point. Describe the actual structure in your report and use the proposed MVC diagram to discuss how these responsibilities could be separated.

## Put the source on GitHub

1. Create a new **private** repository named `customer` in GitHub.
2. Open the repository and choose **Add file → Upload files** or the initial **uploading an existing file** link.
3. Upload the extracted files, including `.gitignore`. Keep `index.php` at the repository root. Skip any `config.local.php`, database dumps with real data or the ZIP itself.
4. Use a commit message such as `Add localhost prototype for sharing and customer comments`. Open the commit and copy its link for your report. Add subsequent commits as you test and improve the project.
5. GitHub stores the source. Run the PHP application through XAMPP on your computer.

For classroom use on localhost. The local credentials are public to anyone with the source.

## Update an existing XAMPP installation

Replace index.php and style.css in your installed folder. In phpMyAdmin import
update_labels.sql once, then refresh the page. This updates the original seeded
names and references without changing passwords or deleting existing shares and
comments. The replacement names and internal references are fictional.
