(function(angular, $, _) {
  "use strict";

  // Declare module
  angular.module('crmSearchPage', CRM.angRequires('crmSearchPage'))

    .config(function($routeProvider) {
      // Load & render a SearchDisplay
      $routeProvider.when('/display/:savedSearchName/:displayName', {
        controller: 'crmSearchPageDisplay',
        // Dynamic template generates the directive for each display type
        template: '<h1 crm-page-title>{{:: $ctrl.display.label }}</h1>\n' +
          '<div ng-include="\'~/crmSearchPage/displayType/\' + $ctrl.display.type + \'.html\'" id="bootstrap-theme"></div>',
        resolve: {
          // Load saved search display
          display: function($route, crmApi4) {
            var params = $route.current.params;
            return crmApi4('SearchDisplay', 'get', {
              where: [['name', '=', params.displayName], ['saved_search.name', '=', params.savedSearchName]],
              select: ['*', 'saved_search.api_entity', 'saved_search.api_params']
            }, 0);
          }
        }
      });
    })

    // Controller for displaying a search
    .controller('crmSearchPageDisplay', function($scope, $routeParams, $location, display) {
      this.display = display;
      this.apiEntity = display['saved_search.api_entity'];
      this.apiParams = display['saved_search.api_params'];
      $scope.$ctrl = this;
    });

})(angular, CRM.$, CRM._);
