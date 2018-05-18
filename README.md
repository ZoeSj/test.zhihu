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

### 远程仓库
**git push**

**git push (-u) origin master**

**git remote add origin git@server-name:path/repo-name.git**:关联一个远程库

#### clone form remote repository
**git clone**

#### branch 
**git checkout -b dev** Switched to a new branch 'dev'

==
```
**git branch dev** 
**git checkout dev**
```
**git branch** 查看当前分支
**git merge dev**把dev分支的工作成功合并到master上
**git merge**用于合并指定分支到当前分支，合并后，就可以看到和dev提交的是一样的。
```
$ git merge dev
Updating d17efd8..fec145a
Fast-forward
 readme.txt |    1 +
 1 file changed, 1 insertion(+)
```
上面的Fast-forward信息，git告诉我妈，这次合并是"快进模式"，也就是直接把master指向dev的当前提交，
**git branch -d dev** 删除分支
```
创建分支：git branch <name>

切换分支：git checkout <name>

创建+切换分支：git checkout -b <name>

合并某分支到当前分支：git merge <name>

删除分支：git branch -d <name>
```

#### [解决冲突](https://www.liaoxuefeng.com/wiki/0013739516305929606dd18361248578c67b8067c8c017b000/001375840202368c74be33fbd884e71b570f2cc3c0d1dcf000)
**git log --graph**查看分支合并图

#### 分支管理策略
通常，合并分支时，如果可能，git会用fast forward模式，但这种模式下，删除分支后，会丢掉分支信息。
如果要强制禁用fast forward模式，git会在merge时生成一个新的commit，这样，从分支历史上就可以看出分支信息。
"--no-ff"
