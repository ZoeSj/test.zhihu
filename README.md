#### this is a test file ,just to do some git excises.
- git status
时刻掌握仓库当前的状态。
- git diff
查看difference，显示的格式是unix通用的diff格式。
- git add 添加文件
- git commit 提交文件到仓库
> ps：在实际项目中，可以使用command+K（phpstorm ide中）提交代码，防止错改了其他文件。

#### **版本回退**
> 像我这样的马大哈经常会提交错误，但是又不知道怎么快速的回退，这就很尴尬了，好在git考虑到我们这种小白。

- git log :this command can tell us what we changed in the file(**git log --pretty=oneline** can make you have a clear watch),if you can't find the latest commit id,you can use this command **git reflog**.

after find the commit id ,what can we do in the next?the command git reset is very magical,we can come back to the code which is we want.

**for instance**:
```
$ git log --pretty=oneline
3628164fb26d48395383f8f31179f24e0882e1e0 append GPL
ea34578d5496d7dd233c827ed32a8cd576c5ee85 add distributed
cb926e7ea50ad11b8f9e909c05226233bf755030 wrote a readme file
```

**HEAD** represent the current version == the latest commit.
the last version = **HEAD^**
the last last version = **HEAD^^**

we not only use HEAD to come back ,but also use commit id to come back.
```
$ git reset --hard HEAD^
HEAD is now at ea34578 add distributed
```
```
$ git reset --hard ea34578d5496d7dd233c827ed32a8cd576c5ee85^
HEAD is now at ea34578 add distributed
```

#### 工作区和暂存区
工作区：Working Directory
which in your local computer. 
版本库：Repository
the working directory have a hide directory(.git),but this is not working directory,it's Git's Repository.
there are lots of things in the Git's repository,the most important is stage(index),and Git build a branch master automatically for us,and point to master called HEAD.

there are two steps add files to the repository:
first step: git add == add change to the stage;
second step:git commit == put the content of stage to the current branch.

when we create Git Repository,Git automatically create a only branch master.

#### 管理修改
now we want to discuss why the git is more popular than other version control system design,because what the git track and manage is the changes,not files.

#### 撤销修改
**git checkout -- file** can back to the last change
意思就是把这个文件在工作区的需改全部撤销，这里有两种情况：
一种是readme.txt自修改后还没有被放到stage，现在，撤销修改就会到和版本库一模一样的状态；
一种是readme.txt已经添加到stage后，又作了修改，现在，撤销修改就回到添加到暂存区的状态。
总之，就是让这个文件回到最近一次git commit or git add 时的状态。

#### 删除文件
in Git,delete = change.
**git rm**delete file from repository.if the file push in repository,don't worry,but need to be careful,you just recover the latest version,and you may lost the last change.