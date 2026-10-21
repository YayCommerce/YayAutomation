/**
 * Email to the customer, wrapped in the WooCommerce email template with an unsubscribe link added.
 * `to` holds one address or a merge tag (e.g. `{{ customer.email }}`); blank `heading` = the subject;
 * `message` is HTML. Fields and checks come from includes/Workflow/Actions/SendEmail.php.
 */
export type SendEmailConfig = { to: string; subject: string; heading: string; message: string };
