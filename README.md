
# Readme and Refactored Code
Here is my thoughts and refactoring version of the original code. This notes consist of general idea of what I think about the code, also how and why would I change the code.

## A. Refactoring Test
### A.1. BookingController.php
path: **app/Http/Controllers/BookingController.php**
##### 1. index()
- For this function, it does not have proper error handling in case the `__authenticatedUser` or repository is not available or doesn't behave as expected.
- Consider using constants instead of env for role IDs for better readability and maintainability.

##### 2. show()
- Make sure that the relationship between translatorJobRel and user are defined correctly in the model associated with the job repository.
- `show()` function seems to follow the typical RESTful convention for retrieving details from specific resource.

##### 3. store()
- This function is a typical RESTful convention for creating a new resource using the POST HTTP method.
Because of that, I added an implementation of error handling using `try-catch` to deal with cases where the creation process fails or encounters issues.

##### 4. update()
- Adding validation for the input data before passing it to the updateJob method to prevent invalid data from being processed.
- Adding error handling to deal with cases where the update process fails.

##### 5. immediateJobEmail()
- Adding Validator for input data before passing it to the storeJobEmail.
- Adding error handling to deal with cases where the email processing fails.

##### 6. distanceFeed()
- Adding error handling and better version of validating input data error handling to deal with cases where the process fails.

##### NOTES:
Overall, this controller mostly missing validation for input data and error handling for the cases where function process fails. Other than that, I would say this is a good controller that has good logic process.

### A.2. BookingRepository.php
path: **app/Repository/BookingRepository.php**
##### 1. getUsersJobs()
This method retrieves jobs for a user based on their user type (customer or translator). Here are some suggestions for this method:
- The method is relatively long and could benefit from being divided into smaller, more focused functions. This would make the code easier to read, understand, and maintain.
- The variable names are generally clear, but there's a typo in `$noramlJobs`. It should be `$normalJobs`.
- There's some code duplication when checking the user type and getting jobs based on that type. Consider refactoring the common logic into separate functions to avoid redundancy.
- Ensure consistent data format for the returned jobs. For example, if 'emergencyJobs' and 'normalJobs' both have the same structure, it makes it easier for the calling code to handle the results.
- The use of string literals like 'yes' and 'new' could benefit from being replaced with constants or enums for better maintainability.
- Add comments to clarify the purpose of the code blocks, especially if there are complex operations or business logic.
- If there's a possibility of exceptions during the job retrieval process, consider adding exception handling to handle such cases gracefully.

##### 2. getUsersJobsHistory()
This method retrieves job history for either a customer or a translator based on their user type. Here are some suggestions for this method:
- The code checks for the existence of the page parameter in the request and sets the page number accordingly. However, Laravel's built-in pagination automatically handles this logic. You can simplify the code by relying on Laravel's pagination without manual handling. But for better performance and easy handling on frontend, this method could use Yajra DataTable.
- The variables $usertype, $emergencyJobs, and $noramlJobs are initialized but not used. If they are unnecessary, you can remove them to simplify the code.
- The logic for fetching jobs is repeated for both customer and translator cases. Consider refactoring the common logic to avoid redundancy.
- There's a typo in the variable name `$noramlJobs`. It should be `$normalJobs`.
- The return statement for the 'translator' case seems to return redundant information. Might want to revise what data is necessary for the response.

##### 3. store()
- Break down the method into smaller, focused functions. Each function should have a single responsibility. Use descriptive variable names to enhance code readability.
- Consider using Laravel's built-in validation mechanisms for input validation, which can help streamline and clarify the validation logic
- There is some repetitive code for error responses. Consider refactoring this part to avoid redundancy.
- Replace magic values like 'yes', 'no', and 5 with constants or config values for better maintainability.
- Ensure consistent date and time handling throughout the method. Currently, there is a mix of formats.
- Consider throwing exceptions for exceptional cases and handling them at an appropriate level.
- Add comments to explain complex logic or decisions if they are not immediately clear from the code.
- Some conditional blocks can be simplified for improved readability.

##### 4. jobToData()
- The code correctly extracts and formats various attributes of the job, such as `due_date` and `due_time`.
- The code contains a bit of redundancy in constructing the job_for array. Consider simplifying the logic by using a mapping array or function to map the certified attribute to the corresponding job types.

##### 5. jobEnd()
- Consider breaking down the method into smaller, more focused functions to improve readability and maintainability. Each function should have a clear responsibility.
- The calculation of the time could be simplified using the `Carbon` library.
- The email sending logic is duplicated for both the user and the translator. You could create a separate function to handle email sending, reducing redundancy.
- Instead of creating a new instance of `AppMailer` within the method, consider injecting it as a dependency. Dependency injection can improve testability and make your code more modular.

##### 6. getPotentialJobIdsWithUserId()
this code aims to fetch potential jobs for a given user based on various criteria, including user type, languages, gender, translator level, and additional conditions related to the jobs.
In the refactored code includes `try-catch` blocks to handle potential exceptions. The `firstOrFail` method is used to throw a `ModelNotFoundException` if the user metadata or job is not found, and these exceptions are caught and handled accordingly. Additionally, a catch-all exception block is included to handle other potential exceptions.

##### 7. sendSMSNotificationToTranslator()
- Instead of hardcoding the SMS number using env('SMS_NUMBER'), consider passing it as a parameter or using a configuration file. This makes the code more flexible and easier to maintain.
- The code does not handle any errors that might occur during the SMS sending process. It might be beneficial to implement proper error handling and logging to capture any issues that could arise.

##### 8. getPotentialTranslators()
- There are repeated sections for different certification types. Consider refactoring the code to reduce duplication and make it more maintainable.
- The complexity of the code can be reduced by simplifying the conditional statements and breaking down the logic into smaller, more manageable functions. This can improve code readability and maintainability.
- It might be beneficial to add error handling, especially when interacting with the database or external services. For example, check if the database query for blacklisted users `(UsersBlacklist::where('user_id', $job->user_id)->get();)` is successful before proceeding.
- There are commented-out sections of code `(foreach ($job_ids as $k => $v) ...)` that appear to be unused. If they are not needed, it's better to remove them to avoid confusion.
- Consider adding a return type hint to the function signature to indicate that the method returns a collection of User objects.

##### 8. updateJob()
- Consider breaking down the method into smaller, more focused functions. This can improve code readability and make the logic easier to understand.
- The `$job->save()` statement is repeated twice. You can move it outside the conditional block to avoid redundancy.
- Consider adding exception handling for the case when `Job::find($id)` returns `null`. If the job is not found, it would be a good practice to handle such cases gracefully (e.g., throw an exception or return an appropriate response).
- The code logic is a bit complex, especially with multiple conditions and checks. Consider simplifying the logic and breaking it down into smaller, focused functions to improve maintainability.
- The logging statement contains HTML `(<a class="openjob" href="/admin/jobs/' . $id . '">)`. While this might be acceptable for certain logging systems, consider separating the logging of data from the presentation. You may want to log raw data and then format it for display separately.
- When comparing dates, consider using the `Carbon` library consistently throughout your code. It provides convenient methods for working with dates.

##### 9. changeStatus()
- Variable names are generally clear, but consider using more expressive names for variables like `$old_status`, `$statusChanged`, and `$log_data`.
- Be consistent with the return type of the function. Currently, the function returns an associative array in some cases and null in others. Consider always returning an associative array to simplify the caller's logic.
- The switch-case logic is appropriate for handling different status cases. However, the individual case methods (`changeTimedoutStatus`, `changeCompletedStatus`, etc.) are not shown in your provided code. Ensure these methods are implemented correctly and consistently.
- The variable `$statusChanged` is assigned twice: once as false and later based on the result of the status change. You can remove the first assignment to improve clarity.
- If the status is not changed, there is no return statement. Consider adding a return statement at the end of the method, possibly returning null or an array indicating that the status was not changed.

##### 10. sendSessionStartRemindNotification()
- The condition for choosing the message based on `customer_physical_type` could be simplified for better readability. Consider assigning the common parts first and appending the specific parts based on the condition.

##### 11. sendChangedTranslatorNotification()
- The logic for getting the user's email appears to be a bit redundant. If `$job->user_email` is not empty, it uses that value; otherwise, it fetches the email from `$user->email`. You can simplify this by directly using `$user->email` in both cases.
- It might be a good idea to check if `$current_translator` is not null before attempting to access properties like `$current_translator->user`. This could prevent potential errors.

##### 12. getUserTagsStringFromArray()
- Suggest using an associative array and then encoding it to `JSON`. This often results in cleaner and more readable code.

##### 13. getPotensialJobs()
- Consider breaking down the logic in this function into smaller, more focused methods. Each method should have a single responsibility, making the code more modular and readable.
- Use early returns to simplify the logic. For example, if certain conditions are met, you can immediately return from the function.
- Try to reduce nesting levels for better readability. Excessive nesting can make the code harder to follow.
- Be consistent with your equality comparisons. For example, use `===` consistently instead of a mix of `==` and `===`.

##### 14. endJob()
- The code to send emails seems to be duplicated. Consider creating a separate method or class responsible for sending emails to avoid redundancy.
- Instead of creating a new `AppMailer` instance within the method, consider injecting the mailer dependency through the constructor or as a method parameter. Dependency injection promotes code reusability and testability.
- The logic for calculating session time and sending emails could be moved to a separate method to improve the clarity of the endJob method.
- Maintain consistent variable naming throughout your code. For example, use either camelCase or snake_case consistently. In your code, you have a mix of both.
- Before sending emails, check if the required email addresses are available. If not, handle this case appropriately. This can help prevent potential errors.
- The response array is initialized with `['status' => 'success']` at the beginning of the method. Instead of redefining it later, you can directly return `['status' => 'success']` at the end of the method.

##### 15. getAll()
- There's some duplication of code between the sections for superadmin and other users. Consider refactoring common logic into separate methods to reduce redundancy.
- Some conditions are quite complex. Consider breaking them down into smaller, more manageable pieces or functions for better readability.
- Instead of using `env('SUPERADMIN_ROLE_ID')` directly in your code, you might want to use Laravel's configuration files to manage such values.

##### 16. bookingExpireNoAccepted()
- Instead of using `Request::all()`, you can type-hint the method to accept a Request object as a parameter, like public function `bookingExpireNoAccepted(Request $request)`. This allows you to access request data through the $request object directly.
- Consider using Eloquent for complex queries instead of building queries with the `Query Builder`. Eloquent provides a more readable syntax and better integration with your models.
- If there are relationships between your models (e.g., between Job and Language), consider defining these relationships in your models. This will make it easier to fetch related data.
- Stick to a consistent naming convention for variables. For example, use camelCase consistently throughout the code.
- Instead of using `Carbon::now()`, you can use `$request->carbon('now')` to create a Carbon instance. This allows you to easily manipulate and format date and time.
- Instead of manually creating arrays of IDs and using `whereIn`, you can use Laravel's whereIn method directly on the query builder.
- There's repetition in the whereIn conditions. You can move this logic outside of the individual if blocks to avoid redundancy.

##### 17. reopen()
- Instead of using date('Y-m-d H:i:s') and Carbon::now(), you can use $request->carbon('now') to create a Carbon instance. This allows you to manipulate and format date and time more conveniently.
- Instead of manually checking whether the job exists and then updating or creating it, you can use Eloquent's `updateOrCreate` method.
- There are similar conditions checking if a job status is not `'timedout'`. You can combine these conditions to make the code cleaner.


##### NOTES:
Overall, this repository has mostly well developed methods with few missing tweaks, but not damaging the logic of the business process. My refactoring version only adds some tweaks of validation data, error handling, JSON encoding for assosiative arrays, and others that required small changes.

- - -
## B. Write Test
B.1. UserRepository
path: **App/Repository/UserRepository.php**
- A new User model is instantiated if $id is null, otherwise, an existing user is retrieved using `findOrFail`. User attributes are then set based on the request data. This includes basic user information like name, email, phone, etc.
- If a new user is being created or an existing user is being updated with a new password, the password is hashed using bcrypt. Password update is conditional on the absence of an $id or the presence of an $id and a non-empty password in the request.
- Existing roles are detached, and the user is assigned the new role specified in the request data. The role is attached using the attachRole method, assuming that this method is part of the user model or a related service.
- The method contains conditional blocks for handling role-specific logic, distinguishing between customers and translators. Specific logic for customers and translators is commented as "// Handling specific logic for customers" and "// Handling specific logic for translators," respectively.
- There's a comment about additional logic for towns, but the specific implementation is not provided in the snippet. It could be related to handling towns associated with users.
- The method checks the status in the request data and compares it with the current status of the user. If the status is '1' (enabled), it enables the user; if '0' (disabled), it disables the user.
- The method returns the user model if it exists, or false if it doesn't. It might be beneficial to provide more specific error information in case of failure.
- There's a dependency on the configuration file (env) for role identifiers (env('CUSTOMER_ROLE_ID') and env('TRANSLATOR_ROLE_ID')). Ensure that these values are correctly set in the environment configuration.
- **Overall Notes**: The method is somewhat lengthy, and specific logic for different roles could be extracted into separate methods for better readability and maintainability. The absence of detailed validation logic for the request data could be a concern. Laravel provides built-in validation mechanisms that could be utilized.
- **Refactored version**:
```
public function createOrUpdate($id = null, $request)
{
    // Instantiate or retrieve existing user
    $model = $this->getUserModel($id);

    // Set basic user attributes
    $this->setBasicAttributes($model, $request);

    // Handle password update
    $this->updatePassword($model, $id, $request);

    // Attach role and handle role-specific logic
    $this->handleRoles($model, $request);

    // Additional logic for towns
    $this->handleTowns($request);

    // Handle user status
    $this->handleStatus($model, $request);

    return $model ?: false;
}

protected function getUserModel($id)
{
    return is_null($id) ? new User : User::findOrFail($id);
}

protected function setBasicAttributes(User $model, $request)
{
    $model->user_type = $request['role'];
    $model->name = $request['name'];
    $model->company_id = $request['company_id'] ?? 0;
    $model->department_id = $request['department_id'] ?? 0;
    $model->email = $request['email'];
    $model->dob_or_orgid = $request['dob_or_orgid'];
    $model->phone = $request['phone'];
    $model->mobile = $request['mobile'];
}

protected function updatePassword(User $model, $id, $request)
{
    if (!$id || ($id && $request['password'])) {
        $model->password = bcrypt($request['password']);
    }
}

protected function handleRoles(User $model, $request)
{
    $model->detachAllRoles();
    $model->attachRole($request['role']);

    // Handle role-specific logic
    if ($request['role'] == env('CUSTOMER_ROLE_ID')) {
        $this->handleCustomerLogic($model, $request);
    } elseif ($request['role'] == env('TRANSLATOR_ROLE_ID')) {
        $this->handleTranslatorLogic($model, $request);
    }
}

protected function handleCustomerLogic(User $model, $request)
{
    // Logic specific to customers
    // ...
}

protected function handleTranslatorLogic(User $model, $request)
{
    // Logic specific to translators
    // ...
}

protected function handleTowns($request)
{
    if ($request['new_towns']) {
        // Logic for handling new towns
        // ...
    }

    // Logic for handling user towns projects
    // ...
}

protected function handleStatus(User $model, $request)
{
    $status = $request['status'] ?? '0';
    $currentStatus = $model->status ?? '';

    if ($status === '1' && $currentStatus !== '1') {
        $this->enable($model->id);
    } elseif ($status === '0' && $currentStatus !== '0') {
        $this->disable($model->id);
    }
}

protected function enable($id)
{
    $user = User::findOrFail($id);
    $user->status = '1';
    $user->save();
}

protected function disable($id)
{
    $user = User::findOrFail($id);
    $user->status = '0';
    $user->save();
}

```
