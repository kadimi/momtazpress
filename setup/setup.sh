#!/bin/bash

# Usage:
#    sh wp-content/themes/gplfan/setup/setup.sh
#    vagrant ssh -c "cd /var/www/public && sh wp-content/plugins/MomtazPress/setup/setup.sh"


###################################################
# Configuration

wp core language install --activate ar

wp rewrite structure                        "/%postname%/"

wp option set blogname                      "ممتازبريس"
wp option set blogdescription               ""

# ###################################################
# # Plugins

# wp plugin deactivate --uninstall --all

# wp plugin install --activate                                                                   \
# 	/var/www/public/wp-content/plugins/MomtazPress/build/releases/momtazpress-plugin-5.2.3.zip \

# ###################################################
# # Cleanup

# wp post delete --force $(wp post list                    \
# 	--post_status=all                                    \
# 	--post_type=attachment,page,post,customize_changeset \
# 	--format=ids                                         \
# )

# wp menu delete $(wp menu list --format=ids)

# ###################################################
# # Theme

wp theme install --activate bootswatch
# wp theme delete twentysixteen twentysixteen twentynineteen

# wp theme mod remove --all

# ###################################################
# # Cleanup
