USE vcl; 

/* Add new privilege */ 
INSERT INTO userprivtype 
            (name) 
VALUES      ('oneClick'); 

/* Create new table and foreign keys */ 
CREATE TABLE oneclick 
  ( 
     id        INT NOT NULL auto_increment, 
     userid    MEDIUMINT(8) NOT NULL, 
     imageid   SMALLINT(5) NOT NULL, 
     name      VARCHAR(70) NOT NULL, 
     duration  INT NOT NULL, 
     autologin TINYINT(1) NOT NULL DEFAULT 0, 
     status    TINYINT NOT NULL DEFAULT 1, 
     PRIMARY KEY (id), 
     INDEX `userid` (`userid`), 
     INDEX `imageid` (`imageid`) 
  ) 
engine=innodb; 

ALTER TABLE oneclick 
  ADD CONSTRAINT oneclick_ibfk_1 FOREIGN KEY (userid) REFERENCES user (id) ON 
  DELETE RESTRICT ON UPDATE RESTRICT; 

ALTER TABLE oneclick 
  ADD CONSTRAINT oneclick_ibfk_2 FOREIGN KEY (imageid) REFERENCES image (id) ON 
  DELETE RESTRICT ON UPDATE RESTRICT; 

ALTER TABLE reservation 
  ADD oneclickid INT(11) after managementnodeid; 

ALTER TABLE reservation 
  ADD CONSTRAINT oneclickfkey FOREIGN KEY (oneclickid) REFERENCES oneclick (id) 
  ON UPDATE CASCADE ON DELETE SET NULL; 

ALTER TABLE reservation 
  ADD userid MEDIUMINT(8) UNSIGNED after oneclickid; 

ALTER TABLE reservation 
  ADD CONSTRAINT useridfkey FOREIGN KEY (userid) REFERENCES user (id) ON UPDATE 
  CASCADE ON DELETE SET NULL; 

ALTER TABLE request 
  ADD oneclickid INT(11) after userid; 

ALTER TABLE request 
  ADD CONSTRAINT oneclickforeignkey FOREIGN KEY (oneclickid) REFERENCES oneclick 
  (id) ON UPDATE CASCADE ON DELETE SET NULL; 

ALTER TABLE oneclick 
  ADD path VARCHAR(100); 