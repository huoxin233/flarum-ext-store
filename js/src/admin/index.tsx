import app from 'flarum/admin/app';
import StoreListPage from './components/StoreListPage';

app.initializers.add('mattoid-store', () => {
  app.extensionData
    .for('mattoid-store')
    .registerPage(StoreListPage)
    .registerPermission(
      {
        icon: 'fas fa-id-card',
        label: app.translator.trans('mattoid-store.admin.settings.group-view'),
        permission: 'mattoid-store.group-view',
        allowGuest: true,
      },
      'view'
    )
    .registerPermission(
      {
        icon: 'fas fa-id-card',
        label: app.translator.trans('mattoid-store.admin.settings.group-moderate'),
        permission: 'mattoid-store.group-moderate',
        // V-03: 管理员权限不应允许游客
        // V-03: admin permission must not allow guests
        allowGuest: false,
      },
      'moderate'
    );
});
