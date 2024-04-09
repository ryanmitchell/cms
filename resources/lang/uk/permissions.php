<?php

return [

    'super' => 'Надкористувач',
    'super_desc' => 'Нададміністратори мають повний контроль та доступ до всього в панелі керування. Призначайте цю роль обережно.',

    'group_cp' => 'Панель Керування',
    'access_cp' => 'Доступ до Панелі Керування',
    'access_cp_desc' => 'Дозволяє доступ до панелі керування, але не гарантує можливості виконання дій у ній.',
    'configure_fields' => 'Налаштувати Поля',
    'configure_fields_desc' => 'Можливість редагувати блупринти, набори полів та їх поля.',
    'configure_addons' => 'Налаштувати Доповнення',
    'configure_addons_desc' => 'Можливість доступу до зони доповнень для їх встановлення та видалення.',
    'manage_preferences' => 'Керувати Налаштуваннями',
    'manage_preferences_desc' => 'Можливість налаштування глобальних та специфічних для ролі переваг.',

    'group_sites' => 'Сайти',
    'access_{site}_site' => 'Доступ до сайту :site',

    'group_collections' => 'Колекції',
    'configure_collections' => 'Налаштувати Колекції',
    'configure_collections_desc' => 'Надає доступ до всіх дозволів, пов’язаних з колекціями',
    'view_{collection}_entries' => 'Перегляд записів :collection',
    'edit_{collection}_entries' => 'Редагувати записи',
    'create_{collection}_entries' => 'Створити нові записи',
    'delete_{collection}_entries' => 'Видалити записи',
    'publish_{collection}_entries' => 'Керувати станом публікації',
    'publish_{collection}_entries_desc' => 'Можливість змінювати стан з чернетки на опубліковане і навпаки',
    'reorder_{collection}_entries' => 'Переупорядкувати записи',
    'reorder_{collection}_entries_desc' => 'Дозволяє переупорядкування методом перетягування',
    'edit_other_authors_{collection}_entries' => 'Редагувати записи інших авторів',
    'publish_other_authors_{collection}_entries' => 'Керувати станом публікації записів інших авторів',
    'delete_other_authors_{collection}_entries' => 'Видалити записи інших авторів',

    'group_taxonomies' => 'Таксономії',
    'configure_taxonomies' => 'Налаштувати Таксономії',
    'configure_taxonomies_desc' => 'Надає доступ до всіх дозволів, пов’язаних з таксономіями',
    'view_{taxonomy}_terms' => 'Перегляд термінів :taxonomy',
    'edit_{taxonomy}_terms' => 'Редагувати терміни',
    'create_{taxonomy}_terms' => 'Створити нові терміни',
    'delete_{taxonomy}_terms' => 'Видалити терміни',
    'publish_{taxonomy}_terms' => 'Керувати станом публікації',
    'reorder_{taxonomy}_terms' => 'Переупорядкувати терміни',

    'group_navigation' => 'Навігація',
    'configure_navs' => 'Налаштувати Навігацію',
    'configure_navs_desc' => 'Надає доступ до всіх дозволів, пов’язаних з навігацією',
    'view_{nav}_nav' => 'Перегляд навігації :nav',
    'edit_{nav}_nav' => 'Редагувати навігацію',

    'group_globals' => 'Глобальні',
    'configure_globals' => 'Налаштувати Глобальні',
    'configure_globals_desc' => 'Надає доступ до всіх дозволів, пов’язаних з глобальними налаштуваннями',
    'edit_{global}_globals' => 'Редагувати глобальні налаштування :global',

    'group_assets' => 'Ресурси',
    'configure_asset_containers' => 'Налаштувати Контейнери Ресурсів',
    'configure_asset_containers_desc' => 'Надає доступ до всіх дозволів, пов’язаних з ресурсами',
    'view_{container}_assets' => 'Перегляд ресурсів :container',
    'upload_{container}_assets' => 'Завантажити нові ресурси',
    'edit_{container}_assets' => 'Редагувати ресурси',
    'move_{container}_assets' => 'Перемістити ресурси',
    'rename_{container}_assets' => 'Перейменувати ресурси',
    'delete_{container}_assets' => 'Видалити ресурси',

    'group_forms' => 'Форми',
    'configure_forms' => 'Налаштувати Форми',
    'configure_forms_desc' => 'Надає доступ до всіх дозволів, пов’язаних з формами',
    'configure_form_fields' => 'Налаштувати Поля Форм',
    'configure_form_fields_desc' => 'Можливість редагувати блупринти форм, набори полів та їх поля.',
    'view_{form}_form_submissions' => 'Перегляд подань :form',
    'delete_{form}_form_submissions' => 'Видалити подання :form',

    'group_users' => 'Користувачі',
    'view_users' => 'Перегляд користувачів',
    'edit_users' => 'Редагувати користувачів',
    'create_users' => 'Створити користувачів',
    'delete_users' => 'Видалити користувачів',
    'change_passwords' => 'Змінити паролі',
    'edit_user_groups' => 'Редагувати групи',
    'edit_roles' => 'Редагувати ролі',
    'assign_user_groups' => 'Призначити користувачам групи',
    'assign_roles' => 'Призначити ролі користувачам',
    'impersonate_users' => 'Входити під користувачами',

    'group_updates' => 'Оновлення',
    'view_updates' => 'Перегляд оновлень',

    'group_utilities' => 'Утиліти',
    'access_utility' => ':title',
    'access_utility_desc' => 'Надає доступ до утиліти :title',

    'group_misc' => 'Різне',
    'resolve_duplicate_ids' => 'Виправити дублювання ідинтифікаторів',
    'resolve_duplicate_ids_desc' => 'Надає можливість переглядати та вирішувати проблему дублювання ідентифікаторів.',

    'view_graphql' => 'Перегляд GraphQL',
    'view_graphql_desc' => 'Надає можливість доступу до переглядача GraphQL',

];
